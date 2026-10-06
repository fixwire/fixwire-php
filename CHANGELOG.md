# Changelog

All notable changes to the Fixwire PHP SDK are listed here. Versions follow [Semantic
Versioning](https://semver.org); before 1.0, a minor version may change the
API.

## [0.1.0] - 2026-10-06

First release.

- Errors with their previous exceptions, uncaught exceptions and fatal errors (out of memory included), warnings as breadcrumbs, and the web request PHP serves tracked from `$_SERVER`.
- Spans with W3C trace context, request sessions (opt-in), cron monitors and feedback, sent at the end of the request.
- PSR-15 middleware (Slim, Mezzio), a Guzzle middleware and a Monolog handler.
- On-device redaction with the server's rules; an error budget for crash loops; rate limits shared by PHP-FPM workers through APCu.
- Examples run against a fake ingest in CI: a Slim API, a cron job and a page without a framework.
