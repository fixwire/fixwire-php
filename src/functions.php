<?php

declare(strict_types=1);

/*
 * The Fixwire SDK for PHP: errors, traces, cron check-ins and feedback, sent over Fixwire protocol
 * v1 (OpenTelemetry's OTLP plus a few Fixwire endpoints).
 *
 *     \Fixwire\init(['dsn' => 'https://fw_pk_live_…@ingest.eu.fixwire.io', 'release' => 'shop@1.4.0']);
 *
 *     try {
 *         charge($order);
 *     } catch (PaymentException $e) {
 *         \Fixwire\captureException($e);
 *     }
 *
 * Without a DSN (and without FIXWIRE_DSN) nothing is sent. What is captured is sent in one go at
 * the end of the request or script; a long-running worker calls \Fixwire\flush() after each job.
 */

namespace Fixwire;

/**
 * Sets the SDK up: the current hub gets a client for the options, uncaught exceptions and fatal
 * errors are reported, and what was captured is sent at the end of the request or script.
 *
 * @param array<string, mixed>|Options $options snake_case keys, as in the docs
 *
 * @throws \InvalidArgumentException for an unknown option or a malformed DSN
 */
function init(array|Options $options = []): Client
{
    $client = new Client($options instanceof Options ? $options : Options::fromArray($options));
    $hub = Hub::current();
    $hub->bindClient($client);
    if ($client->isEnabled() && $client->options()->captureUncaught) {
        ErrorHandler::install();
    } elseif ($client->isEnabled()) {
        FlushAtExit::install();
    }
    if ($client->isEnabled() && $client->options()->trackRequest) {
        ServerRequest::fromGlobals($hub);
    }

    return $client;
}

/** Sends a throwable and its previous ones; its event id, or null when not sent. */
function captureException(\Throwable $exception): ?string
{
    return Hub::current()->captureException($exception);
}

/** Sends a message (at the scope's level, else info); its event id, or null when not sent. */
function captureMessage(string $message, ?Level $level = null): ?string
{
    return Hub::current()->captureMessage($message, $level);
}

/** Sends an event as it is, with what the scope knows; its id, or null when not sent. */
function captureEvent(Event $event): ?string
{
    return Hub::current()->captureEvent($event);
}

/** The id of the last event sent, such as for a feedback form after a crash. */
function lastEventId(): ?string
{
    return Hub::lastEventId();
}

/** Records something that happened: a breadcrumb, or a category and a message. */
function addBreadcrumb(Breadcrumb|string $breadcrumb, ?string $message = null, ?Level $level = null): void
{
    Hub::current()->addBreadcrumb($breadcrumb instanceof Breadcrumb ? $breadcrumb : new Breadcrumb($breadcrumb, $message, $level));
}

/**
 * Changes the current scope.
 *
 * @param callable(Scope): void $change
 */
function configureScope(callable $change): void
{
    $change(Hub::current()->getScope());
}

/**
 * Runs something with a copy of the current scope: what it sets there is gone afterwards.
 *
 * @template T
 *
 * @param callable(Scope): T $work
 *
 * @return T
 */
function withScope(callable $work): mixed
{
    return Hub::current()->withScope($work);
}

/** Sets who the work is for. */
function setUser(?User $user): void
{
    Hub::current()->getScope()->setUser($user);
}

/** Sets a searchable tag. */
function setTag(string $key, ?string $value): void
{
    Hub::current()->getScope()->setTag($key, $value);
}

/**
 * Sets a named group of details.
 *
 * @param array<string, mixed>|null $values
 */
function setContext(string $name, ?array $values): void
{
    Hub::current()->getScope()->setContext($name, $values);
}

/** Sets a detail sent with events. */
function setExtra(string $key, mixed $value): void
{
    Hub::current()->getScope()->setExtra($key, $value);
}

/**
 * Starts a span under the current one (or a new trace), current until it finishes.
 *
 * @param array<string, mixed> $attributes OpenTelemetry's semantic conventions
 */
function startSpan(string $name, ?string $op = null, array $attributes = []): Span
{
    return Hub::current()->startSpan($name, $op, $attributes);
}

/**
 * Runs $work in a span under the current one; the span fails when $work throws, and ends either way.
 *
 * @template T
 *
 * @param callable(Span): T $work
 * @param array<string, mixed> $attributes
 *
 * @return T
 */
function trace(callable $work, string $name, ?string $op = null, array $attributes = []): mixed
{
    $span = Hub::current()->startSpan($name, $op, $attributes);
    try {
        return $work($span);
    } catch (\Throwable $e) {
        $span->setError($e);

        throw $e;
    } finally {
        $span->finish();
    }
}

/** The current span, or null. */
function currentSpan(): ?Span
{
    return Hub::current()->getSpan();
}

/** Reports a run of a scheduled job by hand (see withMonitor()); its id, or null when not sent. */
function captureCheckIn(CheckIn $checkIn): ?string
{
    return Hub::current()->getClient()?->captureCheckIn($checkIn);
}

/**
 * Runs a job as a run of a monitor: in progress, then ok, or error when it throws (the exception goes on).
 *
 * @template T
 *
 * @param callable(): T $job
 *
 * @return T
 */
function withMonitor(string $monitor, ?MonitorConfig $config, callable $job): mixed
{
    $id = captureCheckIn(new CheckIn($monitor, CheckInStatus::InProgress, config: $config));
    $start = microtime(true);
    $status = CheckInStatus::Error;
    try {
        $result = $job();
        $status = CheckInStatus::Ok;

        return $result;
    } finally {
        if ($id !== null) {
            captureCheckIn(new CheckIn($monitor, $status, $id, microtime(true) - $start));
        }
    }
}

/** Sends what someone said about an error or an AI answer; its id, or null when it holds neither a message nor a score. */
function captureFeedback(Feedback $feedback): ?string
{
    return Hub::current()->captureFeedback($feedback);
}

/** Sends what was captured now (workers: after each job); false when something could not be sent. */
function flush(): bool
{
    return Hub::current()->flush();
}
