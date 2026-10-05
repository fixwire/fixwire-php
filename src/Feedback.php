<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * What someone said about an error or an AI answer: a message, a score from -1 (bad) to 1 (good),
 * or both. A negative score on a trace opens a user_feedback issue for the agent run.
 */
final class Feedback
{
    public function __construct(
        public ?string $message = null,
        public float $score = 0.0,
        /** The trace or agent run it is about; by default the current span's. */
        public ?string $traceId = null,
        /** The error it is about, such as \Fixwire\lastEventId(). */
        public ?string $eventId = null,
        /** Their name; by default the scope's user's. */
        public ?string $name = null,
        /** Their email; by default the scope's user's. */
        public ?string $email = null,
        public ?string $url = null,
        /** Where it came from: api (the default), widget, … */
        public ?string $source = null,
    ) {}
}
