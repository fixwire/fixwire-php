<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * A run of a scheduled job, reported to its monitor.
 */
final class CheckIn
{
    public function __construct(
        /** The monitor's slug, such as nightly-report. */
        public string $monitor,
        public CheckInStatus $status = CheckInStatus::Ok,
        /** Ties the end of a run to its start: the id the start returned. Made when null. */
        public ?string $id = null,
        /** How long the run took, in seconds. */
        public ?float $duration = null,
        /** Creates or updates the monitor. */
        public ?MonitorConfig $config = null,
    ) {}
}
