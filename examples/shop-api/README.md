# Shop API (Slim 4)

A small JSON API with Fixwire set up the way a production service would be.

```sh
composer install    # in examples/
FIXWIRE_DSN=https://<key>@<host> php -S localhost:8080 shop-api/public/index.php
```

Under PHP-FPM, point the server at `shop-api/public/index.php` as usual. It
reserves stock at an inventory service (`INVENTORY_URL`, default
`http://localhost:8081`; without one, orders fail at the reservation, and
that is reported too). Then:

```sh
curl localhost:8080/products/sku_1                      # 200, with a database span
curl localhost:8080/products/nope                       # 404: not reported
curl -H 'X-User-Id: user-1' -d '{"sku":"sku_1","card":"4242424242424242"}' localhost:8080/orders
curl -H 'X-User-Id: user-2' -d '{"sku":"sku_2","card":"4000000000000002"}' localhost:8080/orders
curl localhost:8080/admin/report                        # a bug: reported as a crash, answered 500
```

What arrives in Fixwire:

- **The declined payment** as an error of `POST /orders`: the chain
  (`charging order …` caused by `PaymentDeclined`), the user `user-2`, the
  `sku` tag, the order as context, and the breadcrumbs that led to it (the
  `order received` log line, the call to the inventory service). The card
  number stays in the app.
- **The bug** in `GET /admin/report` as a crash (`DivisionByZeroError`),
  with the stack where it happened.
- **A trace per request**, named after its route (`GET /products/{id}`),
  with the database lookup and the call to the inventory service under it.
  The inventory service gets a `traceparent` header and continues the
  trace; other hosts get none (`trace_propagation_targets`).
- **Release health** for `shop-api@1.0.0`: each request is a session, ended
  well, with an error, or crashed.

How it is wired, in `public/index.php`:

```php
Fixwire\init([
    'release' => 'shop-api@1.0.0',
    'traces_sample_rate' => 1.0,
    'trace_propagation_targets' => [$inventoryUrl],
    'auto_session_tracking' => true,
]);
$log = new Logger('shop-api', [new StreamHandler('php://stderr'), new Fixwire\Monolog\Handler()]);
$stack = GuzzleHttp\HandlerStack::create();
$stack->push(Fixwire\Guzzle\Middleware::trace());

// Slim runs the middleware added last first: Fixwire's runs after routing
// (so it knows the route) and inside the error middleware (so it sees what
// handlers throw).
$app->add(new Fixwire\Psr15\Middleware(
    route: fn ($r) => Slim\Routing\RouteContext::fromRequest($r)->getRoute()?->getPattern(),
));
$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, false, false);
```

Handlers set things on their request's scope with `Fixwire\setUser()`,
`Fixwire\setTag()` and the like. What was captured is sent when PHP has
answered the request. Under a worker runtime that serves many requests per
process (RoadRunner, FrankenPHP's worker mode), call `Fixwire\flush()`
after each request.
