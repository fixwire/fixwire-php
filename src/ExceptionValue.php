<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * One exception of an event's chain: the one caught, then its previous ones.
 */
final class ExceptionValue
{
    /**
     * @param list<Frame> $frames the stack, the oldest call first
     */
    public function __construct(
        public string $type,
        public string $message,
        public string $module = '',
        /** How it was caught: generic, uncaught, error_handler, logging, chained (a previous one), … */
        public string $mechanism = 'generic',
        /** False for a crash: nothing handled the exception. */
        public bool $handled = true,
        public array $frames = [],
    ) {}
}
