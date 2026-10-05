<?php

// A small JSON API on Slim 4 reporting to Fixwire: each request gets its own
// scope and trace named after its route, exceptions nothing caught are
// reported as crashes, a failed payment is reported with the order as context,
// the call to the inventory service is traced, and log records become
// breadcrumbs.
//
//   FIXWIRE_DSN=https://<key>@<host> php -S localhost:8080 shop-api/public/index.php

declare(strict_types=1);

use Fixwire\Guzzle\Middleware as FixwireGuzzle;
use Fixwire\Monolog\Handler as FixwireHandler;
use Fixwire\Psr15\Middleware as FixwireMiddleware;
use Fixwire\Scope;
use Fixwire\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteContext;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

/** What the payment provider answers with. */
final class PaymentDeclined extends RuntimeException {}

$inventoryUrl = getenv('INVENTORY_URL') ?: 'http://localhost:8081';

// The DSN comes from FIXWIRE_DSN; without it, Fixwire does nothing.
Fixwire\init([
    'release' => getenv('RELEASE') ?: 'shop-api@1.0.0',
    'traces_sample_rate' => 1.0,
    // Trace headers go to our own inventory service, nowhere else.
    'trace_propagation_targets' => [$inventoryUrl],
    // Each request is a session, for release health (one more request to Fixwire per request).
    'auto_session_tracking' => true,
]);

// Log records become breadcrumbs (and events from ERROR up).
$log = new Logger('shop-api', [new StreamHandler('php://stderr'), new FixwireHandler()]);

// Calls to the inventory service are client spans, with trace headers.
$stack = HandlerStack::create();
$stack->push(FixwireGuzzle::trace());
$inventory = new Client(['handler' => $stack, 'base_uri' => $inventoryUrl, 'timeout' => 5, 'http_errors' => false]);

// The catalog stands in for a database.
$products = [
    'sku_1' => ['id' => 'sku_1', 'name' => 'Mug', 'price_cents' => 1200],
    'sku_2' => ['id' => 'sku_2', 'name' => 'Poster', 'price_cents' => 2500],
];

$app = AppFactory::create();

$app->get('/products/{id}', static function (Request $request, Response $response, array $args) use ($products): Response {
    // A span for the lookup, under the request's.
    $product = Fixwire\trace(static fn() => $products[$args['id']] ?? null, 'SELECT products', 'db.query', ['db.system' => 'postgresql']);
    if ($product === null) {
        return json($response->withStatus(404), ['error' => 'no such product']); // a 404 is not an error worth reporting
    }

    return json($response, $product);
});

$app->post('/orders', static function (Request $request, Response $response) use ($inventory, $log): Response {
    $in = json_decode((string) $request->getBody(), true);
    if (!\is_array($in) || !\is_string($in['sku'] ?? null) || !\is_string($in['card'] ?? null)) {
        return json($response->withStatus(400), ['error' => 'bad order']);
    }
    Fixwire\setTag('sku', $in['sku']);
    $log->info('order received', ['sku' => $in['sku']]);

    try {
        reserve($inventory, $in['sku']);
    } catch (RuntimeException $e) {
        Fixwire\captureException($e);

        return json($response->withStatus(409), ['error' => 'out of stock']);
    }
    $orderId = 'ord_' . bin2hex(random_bytes(4));

    try {
        charge($in['card']);
    } catch (PaymentDeclined $e) {
        // Handled: the customer gets an answer, Fixwire gets the error with the order.
        Fixwire\withScope(static function (Scope $scope) use ($e, $orderId, $in): void {
            $scope->setContext('order', ['id' => $orderId, 'sku' => $in['sku']]);
            Fixwire\captureException(new RuntimeException("charging order {$orderId}", 0, $e));
        });

        return json($response->withStatus(402), ['error' => 'payment declined']);
    }

    return json($response->withStatus(201), ['id' => $orderId]);
});

$app->get('/admin/report', static function (Request $request, Response $response): Response {
    $cents = []; // today's orders: none yet
    // A bug: with no orders this divides by zero. Fixwire reports the crash; Slim answers 500.
    return json($response, ['average_cents' => intdiv(array_sum($cents), \count($cents))]);
});

// The signed-in user (here: a header), on the request's scope.
$app->add(static function (Request $request, Handler $handler): Response {
    $id = $request->getHeaderLine('X-User-Id');
    if ($id !== '') {
        Fixwire\setUser(new User($id));
    }

    return $handler->handle($request);
});
// Slim runs the middleware added last first: Fixwire's runs after routing (so it knows the
// route) and inside the error middleware (so it sees what handlers throw).
$app->add(new FixwireMiddleware(route: static fn(Request $r): ?string => RouteContext::fromRequest($r)->getRoute()?->getPattern()));
$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, false, false);

$app->run();

/** Asks the inventory service to hold one item: a traced call. */
function reserve(Client $inventory, string $sku): void
{
    try {
        $status = $inventory->post('/reservations', ['query' => ['sku' => $sku]])->getStatusCode();
    } catch (GuzzleException $e) {
        throw new RuntimeException("reserving {$sku}", 0, $e);
    }
    if ($status >= 300) {
        throw new RuntimeException("reserving {$sku}: inventory answered {$status}");
    }
}

function charge(string $card): void
{
    if ($card === '4000000000000002') { // the test card that is always declined
        throw new PaymentDeclined('payment declined: card_declined');
    }
}

/** @param array<string, mixed> $body */
function json(Response $response, array $body): Response
{
    $response->getBody()->write((string) json_encode($body));

    return $response->withHeader('Content-Type', 'application/json');
}
