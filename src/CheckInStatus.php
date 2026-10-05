<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * How a run of a scheduled job is going.
 */
enum CheckInStatus: string
{
    case InProgress = 'in_progress';
    case Ok = 'ok';
    case Error = 'error';
}
