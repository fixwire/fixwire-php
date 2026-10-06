<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * @internal the pause after Fixwire didn't answer. Nothing is sent to its host for 10 seconds, then
 * for twice as long each time the try after a pause gets no answer either (up to 5 minutes), until
 * it answers. The PHP-FPM workers of a server share the pause through APCu when it is there, and
 * when it ends only one of them tries: an outage costs one worker one timeout per pause, not every
 * request its timeout. Without APCu, each process has its own.
 */
final class Unreachable
{
    /** The first pause, in seconds. */
    public const FIRST = 10;

    /** The longest pause, in seconds; a pause that ended longer ago than this is forgotten. */
    public const LONGEST = 300;

    /** @var array<string, mixed> what APCu would hold, in a process without it */
    private static array $memory = [];

    /** @var array<string, true> the keys this process used, to forget (tests) */
    private static array $keys = [];

    /** Where the pause of this host is kept; the try is kept at it plus " try". */
    private readonly string $key;

    /** Until when this client holds the try after a pause, if it does: its answer ends the pause. */
    private ?int $trying = null;

    /** @param bool $apcu whether to keep the pause in APCu (shared) rather than in this process */
    public function __construct(string $host, private readonly bool $apcu = false)
    {
        $this->key = 'fixwire.unreachable ' . $host;
        self::$keys[$this->key] = true;
    }

    /** The pause of a Fixwire host: in APCu when it is there and on, else in this process. */
    public static function of(string $host): self
    {
        return new self($host, \function_exists('apcu_fetch') && \function_exists('apcu_enabled') && apcu_enabled());
    }

    /**
     * Whether a request may go at $now: not during a pause; once it has ended, only from the client
     * that takes the try (the first to ask), which holds it until $deadline.
     */
    public function allows(float $now, float $deadline): bool
    {
        $state = $this->state($now);
        if ($state === null) {
            return true;
        }
        if ($now < $state['until']) {
            return false;
        }
        if ($this->trying !== null && $now < $this->trying) {
            return true; // this client is trying
        }
        $until = (int) ceil($deadline);
        if (!$this->take($until, $now)) {
            return false;
        }
        $this->trying = $until;

        return true;
    }

    /** A request got no answer at $now: a pause of FIRST seconds, or twice the last one up to LONGEST. */
    public function failed(float $now): void
    {
        $this->trying = null;
        $state = $this->state($now);
        if ($state !== null && $now < $state['until']) {
            return; // another worker paused for this outage already: not longer for it
        }
        $pause = $state === null ? self::FIRST : min(2 * $state['pause'], self::LONGEST);
        $this->put($this->key, ['until' => $now + $pause, 'pause' => $pause], $pause + self::LONGEST);
        $this->delete($this->key . ' try');
    }

    /** Fixwire answered: when this client took the try, the pause is over for every worker. */
    public function answered(): void
    {
        if ($this->trying !== null) {
            $this->trying = null;
            $this->delete($this->key);
            $this->delete($this->key . ' try');
        }
    }

    /** @internal forgets every pause this process knows of (tests) */
    public static function reset(): void
    {
        if (\function_exists('apcu_delete') && \function_exists('apcu_enabled') && apcu_enabled()) {
            foreach (array_keys(self::$keys) as $key) {
                apcu_delete([$key, $key . ' try']);
            }
        }
        self::$memory = [];
    }

    /**
     * The pause, or null when there is none to remember.
     *
     * @return array{until: float, pause: int}|null
     */
    private function state(float $now): ?array
    {
        $state = $this->fetch($this->key);
        $until = \is_array($state) ? ($state['until'] ?? null) : null;
        $pause = \is_array($state) ? ($state['pause'] ?? null) : null;
        if (!\is_float($until) || !\is_int($pause) || $now > $until + self::LONGEST) {
            return null;
        }

        return ['until' => $until, 'pause' => max(self::FIRST, min($pause, self::LONGEST))];
    }

    /** Takes the try until $until, a Unix time in whole seconds (APCu swaps integers only). */
    private function take(int $until, float $now): bool
    {
        $try = $this->key . ' try';
        $held = $this->fetch($try);
        if (\is_int($held)) {
            return $held <= $now && $this->swap($try, $held, $until); // else another worker is trying
        }
        if ($held !== null) {
            $this->delete($try); // not a time: start again
        }

        return $this->add($try, $until);
    }

    private function fetch(string $key): mixed
    {
        if (!$this->apcu) {
            return self::$memory[$key] ?? null;
        }
        $value = apcu_fetch($key, $found);

        return $found ? $value : null;
    }

    /** @param array<string, mixed> $value */
    private function put(string $key, array $value, int $ttl): void
    {
        if ($this->apcu) {
            apcu_store($key, $value, $ttl);
        } else {
            self::$memory[$key] = $value;
        }
    }

    /** Stores $value unless $key is there: true for the one client that did. */
    private function add(string $key, int $value): bool
    {
        if ($this->apcu) {
            return apcu_add($key, $value, self::LONGEST) === true;
        }
        if (isset(self::$memory[$key])) {
            return false;
        }
        self::$memory[$key] = $value;

        return true;
    }

    /** Replaces $old with $new: true for the one client that did. */
    private function swap(string $key, int $old, int $new): bool
    {
        if ($this->apcu) {
            return apcu_cas($key, $old, $new);
        }
        if ((self::$memory[$key] ?? null) !== $old) {
            return false;
        }
        self::$memory[$key] = $new;

        return true;
    }

    private function delete(string $key): void
    {
        if ($this->apcu) {
            apcu_delete($key);
        } else {
            unset(self::$memory[$key]);
        }
    }
}
