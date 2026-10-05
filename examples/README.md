# Examples

Real apps, each with its own README. The tests in `tests/` run them as they
would run for real (`php -S`, the CLI) against a fake ingest and check what
Fixwire receives, so they keep working:

```sh
composer install && vendor/bin/phpunit
```

| Example | Shows |
|---|---|
| [shop-api](shop-api) | A Slim 4 API: `Fixwire\Psr15\Middleware` (a scope and a server span per request named after its route, a session each, exceptions escaping a handler reported as crashes and answered 500), the signed-in user, handled errors with context, 404s not reported, a database span, a Guzzle call to another service with trace headers sent only to it, Monolog records as breadcrumbs |
| [nightly-report](nightly-report) | A cron job: check-ins to a monitor (created from the first one), one scope per account, carrying on after a failure, a summary warning, a trace for the run, an exit code |
| [order-page](order-page) | A page of an app without a framework: `Fixwire\init()` alone tracks the request (a trace continuing the caller's, the request on events), a PHP warning as a breadcrumb, a `TypeError` nothing catches reported as a crash, the user from the app's session |

They install the SDK from this repository (`"url": ".."` in
`composer.json`); an app of yours runs `composer require fixwire/fixwire`.
