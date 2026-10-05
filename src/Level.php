<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * An event's or a breadcrumb's severity.
 */
enum Level: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Fatal = 'fatal';

    /** OpenTelemetry's severity number. */
    public function severity(): int
    {
        return match ($this) {
            self::Debug => 5,
            self::Info => 9,
            self::Warning => 13,
            self::Error => 17,
            self::Fatal => 21,
        };
    }
}
