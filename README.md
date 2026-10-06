<div align="center">

_Bugs reach production. Fixwire finds them first: errors, traces, logs and
AI agent runs in one place, an AI debugger on every plan, and your data
kept in Europe._

[![Discord](https://img.shields.io/badge/Discord-join%20us-5865F2?logo=discord&logoColor=white)](https://fixwire.io/discord)
[![Slack](https://img.shields.io/badge/Slack-community-4A154B?logo=slack&logoColor=white)](https://fixwire.io/slack)
[![X](https://img.shields.io/badge/X-follow%20us-000000?logo=x&logoColor=white)](https://fixwire.io/x)
[![Release](https://img.shields.io/github/v/release/fixwire/fixwire-php?label=release)](https://github.com/fixwire/fixwire-php/releases)
[![PHP](https://img.shields.io/badge/php-8.1%20%7C%208.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-blue?logo=php&logoColor=white)](https://github.com/fixwire/fixwire-php/actions/workflows/ci.yml)
[![CI](https://github.com/fixwire/fixwire-php/actions/workflows/ci.yml/badge.svg)](https://github.com/fixwire/fixwire-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/fixwire/fixwire-php/blob/main/LICENSE)

<br/>

</div>

# Fixwire SDK for PHP

Welcome to the official PHP SDK for **[Fixwire](https://fixwire.io)**. It
captures errors with their previous exceptions, uncaught exceptions and
fatal errors, traces, release health, cron monitors and user feedback.

## 📦 Getting started

### Prerequisites

- A Fixwire account and project: sign up at [fixwire.io](https://fixwire.io).
- PHP 8.1 or later, with `ext-json` and `ext-mbstring`. Nothing else is
  required: the SDK sends with curl when `ext-curl` is there, else with
  PHP's streams, and compresses what it sends when `ext-zlib` is there.

### Installation

```sh
composer require fixwire/fixwire
```

Laravel and Symfony apps install
[`fixwire/laravel`](https://github.com/fixwire/fixwire-laravel) or
[`fixwire/symfony`](https://github.com/fixwire/fixwire-symfony) instead:
they set everything below up for you and bring this SDK with them.

### Basic configuration

Call `init()` as early as you can, before the app's own code runs:

```php
Fixwire\init([
    'dsn' => 'https://fw_pk_live_…@ingest.eu.fixwire.io', // or FIXWIRE_DSN
    'release' => 'shop@1.4.0',                            // or FIXWIRE_RELEASE
    'environment' => 'production',                        // or FIXWIRE_ENVIRONMENT
    'traces_sample_rate' => 0.2,                          // keep 20% of new traces
    // 'send_default_pii' => true, // send the user's IP address and identifying headers
    // 'redact' => false,          // turn off on-device masking of secrets and personal data
]);
```

The DSN is your project's publishable key and its ingest host:
`https://<publishable key>@<host>`. Without the `dsn` option the SDK reads
`FIXWIRE_DSN`; without either, it does nothing, so local development and
test suites send nothing.

`init()` reports exceptions nothing caught and fatal errors (out of memory
included) as crashes, while PHP still prints them and exits as it would.
PHP warnings and notices become breadcrumbs. When PHP serves a web request
(PHP-FPM, Apache's mod_php, `php -S`), the SDK tracks it: the request's
details go with the events captured while it runs and, with tracing on,
the request is a server span that continues the caller's trace.

PHP runs a request and forgets it, so nothing is sent while the request
runs. What was captured is sent at its end, after your own shutdown
functions, in one request per kind of data. Under PHP-FPM and LiteSpeed
the response has gone out by then: the SDK writes the session and ends
the response first (`fastcgi_finish_request()`, as Symfony and Laravel
do), so your users don't wait for Fixwire. Long-running workers call
`Fixwire\flush()` after each job.

When Fixwire doesn't answer, nothing is sent to it for 10 seconds, then
twice as long each time it still doesn't, up to 5 minutes, until it
answers. With APCu, the PHP-FPM workers of a server share that pause and
only one of them tries again: an outage costs one timeout per pause, not
one per request.

### Quick usage example

```php
// A message: an issue with its text, its level and the breadcrumbs before it.
Fixwire\captureMessage('Hello Fixwire!');

try {
    $payments->charge($order);
} catch (PaymentException $e) {
    // An error: the exception and its previous ones, their stacks and your source lines.
    Fixwire\captureException($e);
}
```

### Errors and context

```php
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

### Tracing

```php
Fixwire\init([/* … */ 'traces_sample_rate' => 0.2]);

$rows = Fixwire\trace(fn () => $db->query($sql), 'SELECT carts', 'db.query');

$span = Fixwire\startSpan('export', 'task');
// …
$span->finish();
```

A span without a parent is sent with the spans under it when it ends.
`Fixwire\Hub::current()->continueTrace($traceparent, $tracestate, $baggage,
'GET /items/{id}')` continues a caller's trace, and the caller's sampling
decision holds. Trace headers go only to `trace_propagation_targets`.

### Release health

Each web request can be a session (ended well, with an error, or crashed),
so Fixwire shows crash-free sessions and users per release. It costs one
more request to Fixwire per request, so it is off:
`'auto_session_tracking' => true` turns it on (it needs a `release`).

### Cron monitors and feedback

```php
Fixwire\withMonitor('nightly-report', Fixwire\MonitorConfig::crontab('0 3 * * *', timezone: 'Europe/Berlin'),
    fn () => buildReports());

Fixwire\captureFeedback(new Fixwire\Feedback('Refunded the wrong order', score: -1, traceId: $runTraceId));
```

Check-ins are sent at once, so Fixwire knows a run started even if it never
ends; the first one creates the monitor from its schedule. A negative
feedback score opens a `user_feedback` issue for the agent run.

## ✨ Why Fixwire

- **Secrets and personal data are masked on the device**, with the same
  rules as the Fixwire server, before anything leaves the app.
- **A crash loop costs a few events and a count**, not your quota: the
  error budget sends an issue's first 10 errors, then one a minute with the
  number held back.
- **It never gets in your app's way.** `init()` and every capture never
  throw; a broken option or DSN is said on PHP's error log and the SDK
  stays off. Memory is bounded (`max_queue`), a flush takes at most
  `timeout` (2 s), and every string, value, stack and request has a strict
  size limit.
- **OpenTelemetry-native.** It speaks the Fixwire protocol (OpenTelemetry's
  OTLP/HTTP plus a few small JSON endpoints): errors, messages and spans
  travel as OTLP, with structured stack traces, breadcrumbs and redaction
  on top.
- **Trace headers only where you allow**: outgoing requests carry them only
  to `trace_propagation_targets`.
- **Your data is kept in Europe.**
- **Made for PHP**: no required packages, rate limits that pause only the
  kind of data they name and, like the pause during an outage, are shared by
  every PHP-FPM worker of a server when APCu is there, and data sent after
  your own shutdown functions, once PHP-FPM has sent the response.

## 🧩 Integrations

| Integration | What it does | How to use |
| --- | --- | --- |
| Plain PHP (PHP-FPM, mod_php, `php -S`) | Tracks the web request from `$_SERVER`: its details on events, a server span continuing the caller's trace, a session with release health on | `Fixwire\init([...])` alone |
| Laravel 11, 12 and 13 | Exceptions Laravel reports, route-named requests, the signed-in user, queue jobs, the scheduler, Octane | [`fixwire/laravel`](https://github.com/fixwire/fixwire-laravel) |
| Symfony 6.4, 7 and 8 | Exceptions the kernel handles, route-named requests, the signed-in user, Messenger, Doctrine DBAL, the HTTP client, console commands | [`fixwire/symfony`](https://github.com/fixwire/fixwire-symfony) |
| PSR-15 (Slim, Mezzio) | A scope and a server span per request, named after the route; exceptions escaping the handler are crashes | `new Fixwire\Psr15\Middleware()` |
| Guzzle | Client spans, trace headers, http breadcrumbs | `Fixwire\Guzzle\Middleware::trace()` |
| Monolog | Records become breadcrumbs (from INFO) and events (from ERROR) | `new Fixwire\Monolog\Handler()` |
| Any HTTP client | A client span, trace headers and a breadcrumb per request | `Fixwire\OutgoingRequest::start()`, then `end($status)` or `fail($e)` |
| Cron jobs | Check-ins to a monitor, created from its schedule | `Fixwire\withMonitor()` |

```php
// Slim: add it where routing has happened (before addRoutingMiddleware).
$app->add(new Fixwire\Psr15\Middleware(
    route: fn ($request) => Slim\Routing\RouteContext::fromRequest($request)->getRoute()?->getPattern(),
));

$stack = GuzzleHttp\HandlerStack::create();
$stack->push(Fixwire\Guzzle\Middleware::trace());
$http = new GuzzleHttp\Client(['handler' => $stack]);

$logger->pushHandler(new Fixwire\Monolog\Handler());

// Any other HTTP client.
$headers = [];
$call = Fixwire\OutgoingRequest::start(Fixwire\Hub::current(), 'GET', $url,
    function (string $name, string $value) use (&$headers): void {
        $headers[$name] = $value;
    });
try {
    $call->end(myClient()->get($url, $headers)->status);
} catch (Throwable $e) {
    $call->fail($e);
    throw $e;
}
```

<a name="configuration"></a>

## ⚙️ Configuration

Options are snake_case keys of the array `init()` takes.

| Option | Default | What it does |
| --- | --- | --- |
| `dsn` | `FIXWIRE_DSN` | Where to send; nothing is sent without one |
| `release` | `FIXWIRE_RELEASE` | The app's version, such as `shop@1.4.0`; release health needs one |
| `environment` | `FIXWIRE_ENVIRONMENT`, else `production` | Where the app runs |
| `service_name` | `OTEL_SERVICE_NAME`, else `api` of `api@1.4.0` | The service's name |
| `server_name` | the host name | The machine's name |
| `sample_rate` | 1 | Share of errors and messages sent |
| `traces_sample_rate` | 0 | Share of new traces kept; continued traces follow the caller |
| `trace_propagation_targets` | none | Where outgoing requests carry trace headers (see below) |
| `before_send`, `before_breadcrumb` | | Change or drop events and breadcrumbs |
| `send_default_pii` | off | Send the user's IP address and identifying request headers |
| `redact`, `sensitive_keys` | on, the server's keys | On-device masking (see below) |
| `error_budget` | 10 per issue, then 1 a minute; 600 a minute | The crash-loop budget |
| `auto_session_tracking` | off | A session per web request, for release health |
| `capture_uncaught` | on | Report uncaught exceptions and fatal errors |
| `track_request` | on | Track the web request PHP serves (framework integrations turn it off) |
| `error_types` | `E_ALL` | The PHP errors that become breadcrumbs |
| `project_root` | the Composer project's root | Files are named relative to it |
| `in_app_include`, `in_app_exclude` | | Class prefixes that are, or are not, your code |
| `context_lines` | 5 | Source lines around each of your frames |
| `max_stack_frames` | 100 | Frames per exception, the newest kept |
| `max_value_length` | 1024 | Bytes of UTF-8 per string sent; longer ones are cut, ending in `...` |
| `max_breadcrumbs`, `max_queue` | 100, 100 | Breadcrumbs kept; events and spans waiting to be sent |
| `timeout` | 2 s | How long a flush may take (at exit, the PHP process waits for it) |
| `finish_request` | on | Under PHP-FPM and LiteSpeed, write the session and end the response before sending at exit. Turn it off if destructors, which PHP runs after the shutdown functions, still print, send headers or change `$_SESSION` |
| `debug` | off | Log what the SDK does and drops to PHP's error log; `FIXWIRE_DEBUG=1` too |

`init()` never throws: an option that doesn't exist or has the wrong type,
or a malformed DSN, is said on PHP's error log (stderr in the CLI) and the
SDK stays off, so a typo neither goes unnoticed nor stops the app.

### Trace propagation targets

URLs are compared without their user info, query and fragment. Targets are
plain strings, not patterns:

- a target with `://` is a URL prefix: `https://api.example.com/v2`
  matches the URLs that start with it (scheme and host in any case);
- any other target is a host, with a port if it has one, and matches that
  host and its subdomains: `example.com` matches `api.example.com`, not
  `badexample.com`;
- a target starting with `/` is a path on a browser page's own origin, so
  on a server it matches nothing.

### Changing or dropping events

```php
Fixwire\init([
    // ...
    'before_send' => function (Fixwire\Event $event): ?Fixwire\Event {
        return str_contains((string) $event->transaction, '/health') ? null : $event;
    },
    'before_breadcrumb' => fn (Fixwire\Breadcrumb $crumb): ?Fixwire\Breadcrumb => $crumb->category === 'db.query' ? null : $crumb,
]);
```

Return the event or breadcrumb (changed or not) to keep it, or `null` to
drop it. A callback that throws is skipped, and what it was given is kept
as it was.

### Sampling

`sample_rate` keeps a share of errors and messages. `traces_sample_rate`
keeps a share of new traces; a trace continued from a caller follows the
caller's decision, so a trace is kept or dropped whole.

### Redaction

Secrets (keys, tokens, passwords, card numbers) and personal data are
masked on the device with the server's rules, in messages, attributes, span
names, breadcrumbs, feedback, URLs and their queries, and map keys. The
app's own configuration (release, environment, service and server name,
monitor slugs) is cut to `max_value_length` but sent as given.
`sensitive_keys` replaces the list of key fragments whose values are
filtered whole, and `'redact' => false` turns masking off.

## 🧪 Examples

Real apps, run by their tests against a fake ingest:

- [shop-api](https://github.com/fixwire/fixwire-php/tree/main/examples/shop-api):
  a Slim 4 API with the PSR-15 middleware, Guzzle and Monolog.
- [nightly-report](https://github.com/fixwire/fixwire-php/tree/main/examples/nightly-report):
  a cron job with check-ins, a scope per account and a trace for the run.
- [order-page](https://github.com/fixwire/fixwire-php/tree/main/examples/order-page):
  a page of an app without a framework, tracked by `init()` alone.

## 📚 Documentation

The full guide lives in this README and the examples.

- [Configuration](https://github.com/fixwire/fixwire-php#configuration)
- [Examples](https://github.com/fixwire/fixwire-php/tree/main/examples)
- [Changelog](https://github.com/fixwire/fixwire-php/blob/main/CHANGELOG.md)
- [Security policy](https://github.com/fixwire/fixwire-php/blob/main/SECURITY.md)
- [Contributing guide](https://github.com/fixwire/fixwire-php/blob/main/CONTRIBUTING.md)

## 🚧 Coming from another error tracker?

The API follows the shape most error-tracking SDKs share: `init`, capture
an exception or a message, the user, tags, breadcrumbs and spans. Moving
over is mostly a change of package and DSN. In PHP the API is functions in
the `Fixwire` namespace (`Fixwire\captureException()`,
`Fixwire\configureScope()`, `Fixwire\withScope()`), options are snake_case
array keys, and data is sent at the end of the request rather than while it
runs.

## 🙌 Want to contribute?

We'd love your help, whether it's a bug report, a fix or a new
integration. Read the
[contributing guide](https://github.com/fixwire/fixwire-php/blob/main/CONTRIBUTING.md),
browse the [open issues](https://github.com/fixwire/fixwire-php/issues), or
pick one of the
[good first issues](https://github.com/fixwire/fixwire-php/issues?q=is%3Aopen+label%3A%22good+first+issue%22).

```sh
composer install
composer check-format && composer analyse && composer test
(cd examples && composer install && vendor/bin/phpunit)
```

## 🛟 Need help?

- Questions: join us on [Discord](https://fixwire.io/discord) or
  [Slack](https://fixwire.io/slack).
- Bugs: open a [GitHub issue](https://github.com/fixwire/fixwire-php/issues).
- Found a security issue? Please don't open an issue; follow the
  [security policy](https://github.com/fixwire/fixwire-php/blob/main/SECURITY.md).

## 🔗 Resources

- [Website](https://fixwire.io)
- [Pricing](https://fixwire.io/pricing)
- [Discord](https://fixwire.io/discord)
- [Slack](https://fixwire.io/slack)
- [X](https://fixwire.io/x)
- [Changelog](https://github.com/fixwire/fixwire-php/blob/main/CHANGELOG.md)
- [Examples](https://github.com/fixwire/fixwire-php/tree/main/examples)
- [Security policy](https://github.com/fixwire/fixwire-php/blob/main/SECURITY.md)

## 📃 License

The SDK is open source under the MIT license; see
[LICENSE](https://github.com/fixwire/fixwire-php/blob/main/LICENSE).

## 😘 Contributors

Thanks to everyone who helps make Fixwire better!

<a href="https://github.com/fixwire/fixwire-php/graphs/contributors"><img src="https://contrib.rocks/image?repo=fixwire/fixwire-php" alt="Contributors" /></a>
