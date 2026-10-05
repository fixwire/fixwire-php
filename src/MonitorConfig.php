<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * When a scheduled job runs, and how late or long it may be: creates or updates its monitor.
 */
final class MonitorConfig
{
    private function __construct(
        private string $type,
        private string|int $value,
        private ?string $unit,
        /** The minutes a check-in may be late. */
        public int $checkInMargin = 0,
        /** The minutes a run may take. */
        public int $maxRuntime = 0,
        /** The schedule's time zone, such as Europe/Berlin. */
        public ?string $timezone = null,
    ) {}

    /** A job that runs on a crontab, such as "0 3 * * *". */
    public static function crontab(string $crontab, int $checkInMargin = 0, int $maxRuntime = 0, ?string $timezone = null): self
    {
        return new self('crontab', $crontab, null, $checkInMargin, $maxRuntime, $timezone);
    }

    /** A job that runs every so many units: minute, hour, day, week, month or year. */
    public static function interval(int $every, string $unit, int $checkInMargin = 0, int $maxRuntime = 0, ?string $timezone = null): self
    {
        return new self('interval', $every, $unit, $checkInMargin, $maxRuntime, $timezone);
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $schedule = ['type' => $this->type, 'value' => $this->value];
        if ($this->unit !== null) {
            $schedule['unit'] = $this->unit;
        }
        $m = ['schedule' => $schedule];
        if ($this->checkInMargin > 0) {
            $m['checkin_margin'] = $this->checkInMargin;
        }
        if ($this->maxRuntime > 0) {
            $m['max_runtime'] = $this->maxRuntime;
        }
        if ($this->timezone !== null) {
            $m['timezone'] = $this->timezone;
        }

        return $m;
    }
}
