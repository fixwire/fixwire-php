<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * OpenTelemetry's span kinds.
 */
enum SpanKind: int
{
    case Internal = 1;
    case Server = 2;
    case Client = 3;
    case Producer = 4;
    case Consumer = 5;
}
