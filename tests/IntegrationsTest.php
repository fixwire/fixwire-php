<?php

declare(strict_types=1);

namespace Fixwire\Tests;

use Fixwire\Client;
use Fixwire\Hub;
use Fixwire\Psr15\Middleware as Psr15Middleware;
use Fixwire\ServerRequest;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest as Psr7ServerRequest;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class IntegrationsTest extends TestCase
{
    protected function setUp(): void
    {
        Client::resetRateLimits();
    }

    public function testTracesGuzzleRequests(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['traces_sample_rate' => 1.0, 'trace_propagation_targets' => ['payments.internal']]);
        $mock = new MockHandler([new Response(201), new Response(503), new ConnectException('refused', new \GuzzleHttp\Psr7\Request('GET', 'http://x')), new Response(200)]);
        $sent = [];
        $stack = HandlerStack::create($mock);
        $stack->push(\Fixwire\Guzzle\Middleware::trace());
        $stack->push(GuzzleMiddleware::history($sent));
        $http = new GuzzleClient(['handler' => $stack, 'http_errors' => false]);

        $root = $hub->startSpan('POST /checkout', 'http.server');
        $http->post('http://payments.internal/charges?card=4242', ['headers' => ['X-Order' => '42']]);
        $http->get('http://payments.internal/status');
        try {
            $http->get('http://payments.internal/down');
        } catch (ConnectException) {
        }
        $http->get('https://api.example.com/rates', ['headers' => ['traceparent' => 'mine']]);
        $root->finish();
        $hub->captureMessage('done');
        $hub->flush();

        self::assertCount(4, $sent);
        $first = $sent[0]['request'];
        self::assertMatchesRegularExpression('/^00-' . $root->traceId . '-[0-9a-f]{16}-01$/', $first->getHeaderLine('traceparent'));
        self::assertNotSame($root->spanId, substr($first->getHeaderLine('traceparent'), 36, 16), 'the client span is the parent');
        self::assertSame('42', $first->getHeaderLine('X-Order'));
        self::assertSame('mine', $sent[3]['request']->getHeaderLine('traceparent'), 'not a target, and its own header stays');

        $spans = FakeIngest::spans($ingest->requests('/v1/traces'));
        $byName = array_column($spans, null, 'name');
        self::assertCount(5, $spans);
        $charge = $byName['POST http://payments.internal/charges'];
        self::assertSame(3, $charge['kind']);
        self::assertSame($root->spanId, $charge['parentSpanId']);
        self::assertSame(substr($first->getHeaderLine('traceparent'), 36, 16), $charge['spanId']);
        self::assertSame(201, FakeIngest::kv($charge['attributes'])['http.response.status_code']);
        self::assertSame('payments.internal', FakeIngest::kv($charge['attributes'])['server.address']);
        self::assertSame(2, $byName['GET http://payments.internal/status']['status']['code']);
        self::assertSame('refused', $byName['GET http://payments.internal/down']['status']['message']);

        $crumbs = FakeIngest::kv(FakeIngest::logRecords($ingest->requests('/v1/logs'))[0]['attributes'])['fixwire.breadcrumbs'];
        self::assertSame(['method' => 'POST', 'url' => 'http://payments.internal/charges', 'status_code' => 201], $crumbs[0]['data']);
        self::assertSame(['error', 'error'], [$crumbs[1]['level'], $crumbs[2]['level']]);
    }

    public function testSendsTraceHeadersWithoutASampledSpan(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['traces_sample_rate' => 0.0, 'trace_propagation_targets' => ['payments.internal']]);
        $sent = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200)]));
        $stack->push(\Fixwire\Guzzle\Middleware::trace());
        $stack->push(GuzzleMiddleware::history($sent));
        $root = $hub->continueTrace('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00', null, 'tenant=7', 'GET /');
        (new GuzzleClient(['handler' => $stack]))->get('http://payments.internal/');
        $root->finish();
        $hub->flush();
        self::assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-' . $root->spanId . '-00', $sent[0]['request']->getHeaderLine('traceparent'));
        self::assertSame('tenant=7', $sent[0]['request']->getHeaderLine('baggage'));
        self::assertSame([], $ingest->requests('/v1/traces'));
    }

    public function testTurnsMonologRecordsIntoBreadcrumbsAndEvents(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub();
        $log = new Logger('shop');
        $log->pushHandler(new \Fixwire\Monolog\Handler());

        $log->debug('not kept');
        $log->info('cart loaded', ['items' => 3]);
        $log->error('payment declined', ['order' => 42]);
        $caught = new \RuntimeException('gateway timeout');
        $log->critical('charge failed', ['exception' => $caught]);
        $seen = new \LogicException('already sent');
        $hub->captureException($seen);
        $log->error('logged after capture', ['exception' => $seen]);
        (new Logger('fixwire'))->pushHandler(new \Fixwire\Monolog\Handler())->error('the SDK itself');
        $hub->flush();

        $recs = FakeIngest::logRecords($ingest->requests('/v1/logs'));
        self::assertCount(3, $recs);
        $declined = FakeIngest::kv($recs[0]['attributes']);
        self::assertSame('payment declined', FakeIngest::anyValue($recs[0]['body']));
        self::assertSame(17, $recs[0]['severityNumber']);
        self::assertSame(['shop', 42], [$declined['logger'], $declined['order']]);
        self::assertSame('cart loaded', $declined['fixwire.breadcrumbs'][0]['message']);
        self::assertSame(['items' => 3], $declined['fixwire.breadcrumbs'][0]['data']);
        self::assertCount(1, $declined['fixwire.breadcrumbs'], 'debug is below the breadcrumb level');

        $charge = FakeIngest::kv($recs[1]['attributes']);
        self::assertSame(21, $recs[1]['severityNumber']);
        self::assertSame('gateway timeout', $charge['exception.message']);
        self::assertSame('logging', $charge['fixwire.exceptions'][0]['mechanism']['type']);
        self::assertSame('already sent', FakeIngest::kv($recs[2]['attributes'])['exception.message']);
    }

    public function testSendsOnceWhenSendingLogsAgain(): void
    {
        $log = new Logger('shop');
        $log->pushHandler(new \Fixwire\Monolog\Handler());
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['before_send' => static function (\Fixwire\Event $e) use ($log): \Fixwire\Event {
            $log->error('sending ' . $e->message);

            return $e;
        }]);
        $log->error('payment declined');
        $hub->flush();
        $recs = FakeIngest::logRecords($ingest->requests('/v1/logs'));
        self::assertSame(['payment declined'], array_map(static fn(array $r): mixed => FakeIngest::anyValue($r['body']), $recs));
    }

    public function testTracksPsr15Requests(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['traces_sample_rate' => 1.0, 'auto_session_tracking' => true, 'release' => 'shop@1']);
        $middleware = new Psr15Middleware(route: static fn(ServerRequestInterface $r): ?string => $r->getAttribute('route'));
        $handler = new class ($hub) implements RequestHandlerInterface {
            public function __construct(private Hub $hub) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if ($request->getUri()->getPath() === '/boom') {
                    throw new \DomainException('no stock');
                }
                $this->hub->captureMessage('in handler');

                return new Response(404);
            }
        };
        $request = (new Psr7ServerRequest('GET', 'https://shop.example.com/items/7?ref=ad', [
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            'Authorization' => 'Bearer secret',
            'User-Agent' => 'curl/8',
        ], null, '1.1', ['REMOTE_ADDR' => '203.0.113.9']))->withAttribute('route', '/items/{id}');
        self::assertSame(404, $middleware->process($request, $handler)->getStatusCode());
        try {
            $middleware->process(new Psr7ServerRequest('POST', 'https://shop.example.com/boom'), $handler);
            self::fail('swallowed');
        } catch (\DomainException) {
        }
        self::assertNull($hub->getScope()->getRequest(), 'the request was on its own scope');
        $hub->flush();

        $recs = FakeIngest::logRecords($ingest->requests('/v1/logs'));
        $msg = FakeIngest::kv($recs[0]['attributes']);
        self::assertSame('GET /items/{id}', $msg['fixwire.transaction']);
        self::assertSame('https://shop.example.com/items/7', $msg['url.full']);
        self::assertSame('ref=ad', $msg['url.query']);
        self::assertSame('/items/{id}', $msg['http.route']);
        self::assertSame('curl/8', $msg['user_agent.original']);
        self::assertArrayNotHasKey('http.request.header.authorization', $msg, 'without send_default_pii');
        self::assertArrayNotHasKey('client.address', $msg);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $recs[0]['traceId']);
        $boom = FakeIngest::kv($recs[1]['attributes']);
        self::assertSame('psr15', $boom['fixwire.exceptions'][0]['mechanism']['type']);
        self::assertFalse($boom['fixwire.handled']);

        $spans = array_column(FakeIngest::spans($ingest->requests('/v1/traces')), null, 'name');
        self::assertSame(['GET /items/{id}', 'POST /boom'], array_keys($spans));
        $get = $spans['GET /items/{id}'];
        self::assertSame('00f067aa0ba902b7', $get['parentSpanId']);
        self::assertSame(2, $get['kind']);
        self::assertSame(404, FakeIngest::kv($get['attributes'])['http.response.status_code']);
        self::assertSame(['code' => 1], $get['status'], 'a 4xx is not a server error');
        self::assertSame(2, $spans['POST /boom']['status']['code']);

        $sessions = $ingest->requests('/v1/sessions')[0]['body']['aggregates'];
        self::assertSame([1, 1], [array_sum(array_column($sessions, 'exited')), array_sum(array_column($sessions, 'crashed'))]);
    }

    public function testReadsTheRequestFromServerGlobals(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['send_default_pii' => true]);
        $tracked = ServerRequest::fromGlobals($hub, [
            'REQUEST_METHOD' => 'PUT',
            'REQUEST_URI' => '/carts/9?coupon=X',
            'HTTPS' => 'on',
            'HTTP_HOST' => 'shop.example.com',
            'HTTP_AUTHORIZATION' => 'Bearer secret',
            'HTTP_X_REQUEST_ID' => 'r-1',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '203.0.113.9',
            'argv' => [],
        ]);
        self::assertNotNull($tracked);
        self::assertSame($tracked, ServerRequest::global());
        $tracked->setRoute('/carts/{id}');
        $hub->captureMessage('x');
        $tracked->end(200);
        self::assertNull(ServerRequest::global());
        $hub->flush();
        $a = FakeIngest::kv(FakeIngest::logRecords($ingest->requests())[0]['attributes']);
        self::assertSame('https://shop.example.com/carts/9', $a['url.full']);
        self::assertSame('PUT /carts/{id}', $a['fixwire.transaction']);
        self::assertSame('[Filtered]', $a['http.request.header.authorization'], 'sent with send_default_pii, its value still redacted');
        self::assertSame('r-1', $a['http.request.header.x-request-id']);
        self::assertSame('application/json', $a['http.request.header.content-type']);
        self::assertSame('203.0.113.9', $a['client.address']);
        self::assertNull(ServerRequest::fromGlobals($hub, ['argv' => []]), 'not a web request');
    }
}
