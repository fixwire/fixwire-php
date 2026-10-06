# Fixwire for PHP

[![CI](https://github.com/fixwire/fixwire-php/actions/workflows/ci.yml/badge.svg)](https://github.com/fixwire/fixwire-php/actions/workflows/ci.yml)

The Fixwire SDK for PHP 8.1+: errors with their previous exceptions, fatal
errors, traces, release health, cron monitors and feedback. It needs only
`ext-json` and `ext-mbstring`; it sends with curl when it is there, else
with PHP's streams.

```sh
composer require fixwire/fixwire
```

```php
Fixwire\init([
    'dsn' => 'https://fw_pk_live_…@ingest.eu.fixwire.io', // or FIXWIRE_DSN
    'release' => 'shop@1.4.0',
]);
```

Call it as early as you can, before the app's own code runs. Without a DSN
(and without `FIXWIRE_DSN`) the SDK does nothing.

`init()` reports exceptions nothing caught and fatal errors (out of memory
included) as crashes, while PHP still prints them and exits as it would;
PHP warnings and notices become breadcrumbs. When PHP serves a web request
(PHP-FPM, Apache's mod_php, `php -S`), it tracks it: the request's details
go with the events captured while it runs and, with tracing on, the request
is a server span that continues the caller's trace.

PHP runs a request and forgets it, so nothing is sent while the request
runs: what was captured is sent at its end, after your own shutdown
functions, in one request per kind of data. Long-running workers call
`Fixwire\flush()` after each job.

**What's different**
- Secrets and personal data are masked on the device, with the same rules
  as the Fixwire server (`'redact' => false` turns it off).
- A crash loop costs a few events and a count, not your quota
  (`error_budget`).
- Sending honours rate limits, pausing only the kind of data a limit names,
  for every PHP-FPM worker of the server when APCu is there.
- It speaks the Fixwire protocol: errors, messages and spans travel as
  OpenTelemetry's OTLP/HTTP (JSON), with structured stack traces,
  breadcrumbs and redaction on top.

## Errors

```php
try {
    $payments->charge($order);
} catch (PaymentException $e) {
    Fixwire\captureException($e);
}

Fixwire\setUser(new Fixwire\User('user-1'));
Fixwire\setTag('plan', 'team');
Fixwire\setContext('order', ['id' => $order->id, 'items' => 3]);
Fixwire\addBreadcrumb('cart', 'checkout started');
Fixwire\captureMessage('disk usage above 90%', Fixwire\Level::Warning);
```

An exception is sent with its previous ones and their stacks, with the
source lines around your frames. Files are named relative to your Composer
project (`src/Cart.php`); frames under `vendor/` are marked as not yours
(`in_app_include` and `in_app_exclude` adjust it). Closures are
`{closure}`, so an issue doesn't move when you edit the file.

`Fixwire\withScope()` gives a piece of work its own copy of the scope: what
it sets is gone afterwards.

```php
Fixwire\withScope(function (Fixwire\Scope $scope) use ($account) {
    $scope->setTag('account', $account);
    buildReport($account);
});
```

## Frameworks and HTTP clients

Laravel apps install [`fixwire/laravel`](https://github.com/fixwire/fixwire-laravel) instead: it sets all of
this up from `config/fixwire.php`, with queue jobs and scheduled tasks.
Symfony apps install [`fixwire/symfony`](https://github.com/fixwire/fixwire-symfony): the same from
`config/packages/fixwire.yaml`, with Messenger, Doctrine and the HTTP
client.

| | |
|---|---|
| Slim, Mezzio, any PSR-15 app | `Fixwire\Psr15\Middleware`: a server span named after the route, exceptions escaping the handler as crashes |
| Guzzle | `Fixwire\Guzzle\Middleware::trace()`: client spans, trace headers, http breadcrumbs |
| Monolog | `Fixwire\Monolog\Handler`: records become breadcrumbs (from INFO) and events (from ERROR) |
| Any HTTP client | `Fixwire\OutgoingRequest::start()`, then `end($status)` or `fail($e)` |

```php
$app->add(new Fixwire\Psr15\Middleware(
    route: fn ($request) => Slim\Routing\RouteContext::fromRequest($request)->getRoute()?->getPattern(),
));

$stack = GuzzleHttp\HandlerStack::create();
$stack->push(Fixwire\Guzzle\Middleware::trace());
$http = new GuzzleHttp\Client(['handler' => $stack]);

$logger->pushHandler(new Fixwire\Monolog\Handler());
```

## Tracing

```php
Fixwire\init([..., 'traces_sample_rate' => 0.2]);

$rows = Fixwire\trace(fn () => $db->query($sql), 'SELECT carts', 'db.query');

$span = Fixwire\startSpan('export', 'task');
// …
$span->finish();
```

A span without a parent is sent with the spans under it when it ends.
`Fixwire\Hub::current()->continueTrace($traceparent, $tracestate, $baggage,
'GET /items/{id}')` continues a caller's trace; its sampling decision holds.
Trace headers go only to `trace_propagation_targets`.

## Release health

Each web request can be a session (ended well, with an error, or crashed),
so Fixwire shows crash-free sessions and users per release. It costs one
more request to Fixwire per request, so it is off:
`'auto_session_tracking' => true` turns it on (it needs a `release`).

## Cron jobs and feedback

```php
Fixwire\withMonitor('nightly-report', Fixwire\MonitorConfig::crontab('0 3 * * *', timezone: 'Europe/Berlin'),
    fn () => buildReports());

Fixwire\captureFeedback(new Fixwire\Feedback('Refunded the wrong order', score: -1, traceId: $runTraceId));
```

Check-ins are sent at once, so Fixwire knows a run started even if it never
ends. A negative score opens a `user_feedback` issue for the agent run.

## Options

| Option | Default | |
|---|---|---|
| `dsn` | `FIXWIRE_DSN` | Where to send; nothing is sent without one |
| `release`, `environment` | `FIXWIRE_RELEASE`, `production` | Release health needs a release |
| `service_name` | `OTEL_SERVICE_NAME`, else `api` of `api@1.4.0` | |
| `server_name` | the host name | |
| `sample_rate` | 1 | Share of errors sent |
| `traces_sample_rate` | 0 | Share of new traces kept |
| `trace_propagation_targets` | none | URLs that receive trace headers |
| `before_send`, `before_breadcrumb` | | Change or drop events and breadcrumbs |
| `send_default_pii` | off | Send the user's IP address and identifying headers |
| `redact`, `sensitive_keys` | on, the server's keys | On-device masking |
| `error_budget` | 10 per issue, then 1 a minute; 600 a minute | |
| `auto_session_tracking` | off | A session per web request |
| `capture_uncaught` | on | Report uncaught exceptions and fatal errors |
| `track_request` | on | Track the web request PHP serves (frameworks' integrations turn it off) |
| `error_types` | `E_ALL` | The PHP errors that become breadcrumbs |
| `project_root` | the Composer project's root | Files are named relative to it |
| `in_app_include`, `in_app_exclude` | | Class prefixes that are, or are not, your code |
| `context_lines` | 5 | Source lines around each of your frames |
| `max_breadcrumbs`, `max_queue` | 100, 100 | |
| `timeout` | 2 s | Of each request to Fixwire |

An option that doesn't exist is an error, so a typo doesn't go unnoticed.

## Examples

[examples](examples) holds real apps, run by its tests against a fake
ingest: a Slim API ([shop-api](examples/shop-api)), a cron job
([nightly-report](examples/nightly-report)) and a page of an app without a
framework ([order-page](examples/order-page)).

## Building

```sh
composer install
composer test && composer analyse && composer check-format
(cd examples && composer install && vendor/bin/phpunit)
```

## License

MIT.
