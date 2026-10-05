<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * An error or a message, as it is sent. The before_send option sees it after the scope's details
 * are added.
 */
final class Event
{
    public ?string $eventId = null;

    /** Unix seconds. */
    public ?float $timestamp = null;

    public ?Level $level = null;

    public ?string $message = null;

    /** @var list<ExceptionValue> the chain, the outermost first; empty for a message */
    public array $exceptions = [];

    /** @var array<string, string> */
    public array $tags = [];

    /** @var array<string, array<string, mixed>> */
    public array $contexts = [];

    /** @var array<string, mixed> */
    public array $extra = [];

    public ?User $user = null;

    /** @var list<Breadcrumb> */
    public array $breadcrumbs = [];

    /** @var list<string> a custom grouping; {{ default }} stands for Fixwire's own */
    public array $fingerprint = [];

    public ?string $transaction = null;

    public ?Request $request = null;

    public ?string $traceId = null;

    public ?string $spanId = null;

    /** The throwable it was made from, for before_send. */
    public ?\Throwable $throwable = null;

    /** @internal occurrences the error budget held back */
    public int $suppressed = 0;
}
