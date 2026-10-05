<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * @internal the error budget at work: a crash loop costs a few events and a count, not the quota.
 * Each issue (a cheap fingerprint; the server's grouping is the real one) may send a burst, then so
 * many a minute, within a budget for all of them; occurrences held back ride on the issue's next
 * event.
 */
final class Budget
{
    private const MAX_ISSUES = 1024;
    private const TOP_FRAMES = 5;

    /** Parts of a message that change between occurrences. */
    private const VARIABLE = '/\b0x[0-9a-fA-F]+\b|\b[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\b|'
        . '\b[0-9a-fA-F]{16,}\b|[0-9]+(?:\.[0-9]+)?|\S+@\S+\.\w+/';

    private int $burst;

    private float $perIssuePerMinute;

    private float $perMinute;

    private bool $enabled;

    /** @var array{tokens: float, updated: float, suppressed: int} */
    private array $all;

    /** @var array<string, array{tokens: float, updated: float, suppressed: int}> least recently seen first */
    private array $issues = [];

    /**
     * @param array{per_issue_burst?: int, per_issue_per_minute?: float, per_minute?: float, enabled?: bool} $options
     */
    public function __construct(array $options)
    {
        $this->burst = max((int) ($options['per_issue_burst'] ?? 10), 1);
        $this->perIssuePerMinute = max((float) ($options['per_issue_per_minute'] ?? 1), 0.0);
        $this->perMinute = max((float) ($options['per_minute'] ?? 600), 1.0);
        $this->enabled = (bool) ($options['enabled'] ?? true);
        $this->all = ['tokens' => $this->perMinute, 'updated' => microtime(true), 'suppressed' => 0];
    }

    /** Whether an event of the issue may be sent: -1 when not, else the occurrences held back since the last one sent. */
    public function allow(string $issue, float $now): int
    {
        if (!$this->enabled) {
            return 0;
        }
        $b = $this->issues[$issue] ?? ['tokens' => (float) $this->burst, 'updated' => $now, 'suppressed' => 0];
        unset($this->issues[$issue]); // re-added last: recently seen
        if (\count($this->issues) >= self::MAX_ISSUES) {
            array_shift($this->issues);
        }
        $ok = self::take($b, $this->burst, $this->perIssuePerMinute, $now) && self::take($this->all, $this->perMinute, $this->perMinute, $now);
        if ($ok) {
            $held = $b['suppressed'];
            $b['suppressed'] = 0;
        } else {
            $b['suppressed']++;
            $held = -1;
        }
        $this->issues[$issue] = $b;

        return $held;
    }

    /**
     * @param array{tokens: float, updated: float, suppressed: int} $bucket
     *
     * @param-out array{tokens: float, updated: float, suppressed: int} $bucket
     */
    private static function take(array &$bucket, float $burst, float $perMinute, float $now): bool
    {
        $bucket['tokens'] = min($burst, $bucket['tokens'] + ($now - $bucket['updated']) / 60 * $perMinute);
        $bucket['updated'] = $now;
        if ($bucket['tokens'] >= 1) {
            $bucket['tokens']--;

            return true;
        }

        return false;
    }

    /** For tests: makes the issue's bucket older. */
    public function age(string $issue, float $seconds): void
    {
        if (isset($this->issues[$issue])) {
            $this->issues[$issue]['updated'] -= $seconds;
        }
    }

    /**
     * The event's fingerprint for the budget: its exception types and top in-app frames (or its
     * message without the parts that vary), and its custom fingerprint.
     */
    public static function issueOf(Event $e): string
    {
        $parts = [];
        if ($e->exceptions !== []) {
            foreach ($e->exceptions as $x) {
                $parts[] = $x->type;
            }
            // The innermost exception threw: its frames say where.
            $thrower = $e->exceptions[\count($e->exceptions) - 1];
            $frames = $thrower->frames !== [] ? $thrower->frames : $e->exceptions[0]->frames;
            $app = array_values(array_filter($frames, static fn(Frame $f) => $f->inApp));
            if ($app === []) {
                $app = $frames;
            }
            foreach (\array_slice($app, -self::TOP_FRAMES) as $f) {
                $parts[] = $f->module . '|' . $f->function;
            }
            if ($frames === []) {
                $parts[] = (string) preg_replace(self::VARIABLE, '<*>', $e->exceptions[0]->message);
            }
        } else {
            $parts[] = (string) preg_replace(self::VARIABLE, '<*>', (string) $e->message);
        }
        if ($e->fingerprint !== []) {
            $parts[] = implode("\x1f", $e->fingerprint);
        }

        return hash('fnv1a64', implode("\x1e", $parts));
    }
}
