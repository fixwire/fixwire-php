# Changelog

All notable changes to the Fixwire PHP SDK are listed here. Versions follow [Semantic
Versioning](https://semver.org); before 1.0, a minor version may change the
API.

## [Unreleased]

- Long messages no longer stall a capture: the error budget reads only a message's first kilobyte (300 kB of some text took seconds).
- Without curl, redirects are no longer followed (they took the key to the new host), and neither transport keeps more than 64 kB of an answer.
- A 5xx with Retry-After pauses sending until then, as a 429 does; no pause lasts more than an hour; a flush stops at the first request Fixwire doesn't answer.
- Log records and spans go in requests of at most 4 MB, so one large error doesn't lose the others.
- An iterator in an event's data is read up to 1,000 items (an endless generator ran out of memory).
- Incoming `tracestate` and `baggage` over 8 kB or with line breaks are not passed on.
- Feedback counts towards `max_queue`; a `before_breadcrumb` that returns something else than a breadcrumb drops it instead of throwing; the Monolog handler doesn't send what is logged while it sends.
- Adding a breadcrumb no longer copies all of them, and the error budget forgets its oldest issue in constant time.

## [0.1.0] - 2026-10-06

First release.

- Errors with their previous exceptions, uncaught exceptions and fatal errors (out of memory included), warnings as breadcrumbs, and the web request PHP serves tracked from `$_SERVER`.
- Spans with W3C trace context, request sessions (opt-in), cron monitors and feedback, sent at the end of the request.
- PSR-15 middleware (Slim, Mezzio), a Guzzle middleware and a Monolog handler.
- On-device redaction with the server's rules; an error budget for crash loops; rate limits shared by PHP-FPM workers through APCu.
- Examples run against a fake ingest in CI: a Slim API, a cron job and a page without a framework.
