# Changelog

All notable changes to the Fixwire PHP SDK are listed here. Versions follow [Semantic
Versioning](https://semver.org); before 1.0, a minor version may change the
API.

## [Unreleased]

- Long messages no longer stall a capture: the error budget reads only a message's first kilobyte (300 kB of some text took seconds).
- Without curl, redirects are no longer followed (they took the key to the new host), and neither transport keeps more than 64 kB of an answer.
- A 5xx with Retry-After pauses sending until then, as a 429 does; no pause lasts more than a day; a flush stops at the first request Fixwire doesn't answer.
- Retry-After may be an HTTP date; broken seconds and categories the protocol doesn't name are ignored (`60:nonsense` paused everything); pauses shared through APCu keep the other workers' pauses of other categories.
- The one retry waits a second, and `timeout` (2 s) bounds a whole flush, `flush($timeout)` too, rather than each request: at exit the app waits at most that long.
- Log records and spans go in requests of at most 100 and 5 MB, the export included; one that can't fit alone is dropped alone. An error or a message over 1 MB leaves out its breadcrumbs, then its contexts, and is dropped if still over.
- Strings are at most `max_value_length` (new, 1,024) bytes, cut on a character boundary and ending in `...`; `exception.stacktrace` at most 64 kB. Redaction reads 16 kB past the cut, so a key or token the cut goes through is still masked. Span names and status messages are redacted too.
- Values are at most 10 levels deep and 100 items wide, 10,000 items walked in all (an iterator is read up to 100): `[Circular ~]`, `[Object]`, `[Array]`, `[Unreadable]` (a throwing `jsonSerialize` or `__toString`), and `NaN`, `Infinity`, `-Infinity`.
- `max_stack_frames` (new, 100): the newest frames of an exception (the 100th was named `{main}`). Source lines come from files of up to 10 MB, read around the frame through a cache of 64 files and 16 MB.
- A span keeps 128 attributes; sessions count 5,000 users apart per send, then without their user, in requests of at most 5,000 aggregates.
- An incoming `traceparent` must be version `00` with exactly four fields; `tracestate` over 512 bytes, `baggage` over 8 kB, or either with a control character, is not passed on.
- `trace_propagation_targets` are URL prefixes (with `://`) or hosts with their subdomains, compared without user info, query and fragment: `example.com` no longer matches `badexample.com` or `https://evil.net/?example.com`.
- Redaction has the server's new `secret_assignment` (access_token, client_secret, csrfToken, PHPSESSID, X-Amz-Signature, `?code=`), linear in PCRE; a value a pattern fails on (PCRE's limits) is sent as `[Filtered]`, and numbering map keys that mask alike is linear.
- The Monolog handler never throws into the app's logging.
- Feedback counts towards `max_queue`; a `before_breadcrumb` that returns something else than a breadcrumb drops it instead of throwing; the Monolog handler doesn't send what is logged while it sends.
- Adding a breadcrumb no longer copies all of them, and the error budget forgets its oldest issue in constant time.

## [0.1.0] - 2026-10-06

First release.

- Errors with their previous exceptions, uncaught exceptions and fatal errors (out of memory included), warnings as breadcrumbs, and the web request PHP serves tracked from `$_SERVER`.
- Spans with W3C trace context, request sessions (opt-in), cron monitors and feedback, sent at the end of the request.
- PSR-15 middleware (Slim, Mezzio), a Guzzle middleware and a Monolog handler.
- On-device redaction with the server's rules; an error budget for crash loops; rate limits shared by PHP-FPM workers through APCu.
- Examples run against a fake ingest in CI: a Slim API, a cron job and a page without a framework.
