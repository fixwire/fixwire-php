<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * @internal release health: each request is a session, counted per minute and user, and sent
 * with the rest when the client flushes (sdks/PROTOCOL.md §5)
 */
final class Sessions
{
    /** The users counted apart per send; past them, requests are counted without their user. */
    private const MAX_USERS = 5000;

    /** The aggregates per request to Fixwire. */
    private const MAX_AGGREGATES = 5000;

    /** @var array<string, array{minute: int, did: ?string, counts: array{int, int, int}}> exited, errored, crashed */
    private array $buckets = [];

    /** @var array<string, true> the users counted apart since the last send */
    private array $users = [];

    public function record(string $status, ?string $did, float $at): void
    {
        if ($did !== null && !isset($this->users[$did])) {
            if (\count($this->users) >= self::MAX_USERS) {
                $did = null;
            } else {
                $this->users[$did] = true;
            }
        }
        $minute = (int) (floor($at / 60) * 60);
        $key = $minute . '|' . ($did ?? '');
        $this->buckets[$key] ??= ['minute' => $minute, 'did' => $did, 'counts' => [0, 0, 0]];
        $this->buckets[$key]['counts'][$status === 'crashed' ? 2 : ($status === 'errored' ? 1 : 0)]++;
    }

    /**
     * What was counted, as /v1/sessions bodies of at most MAX_AGGREGATES each; forgets it.
     *
     * @return list<array<string, mixed>>
     */
    public function take(Options $options): array
    {
        $aggregates = [];
        foreach ($this->buckets as $b) {
            $a = ['started' => gmdate('Y-m-d\TH:i:s\Z', $b['minute'])];
            if ($b['did'] !== null) {
                $a['did'] = $b['did'];
            }
            [$a['exited'], $a['errored'], $a['crashed']] = $b['counts'];
            $aggregates[] = $a;
        }
        $this->buckets = $this->users = [];
        // The app's own configuration: cut, not redacted.
        $config = Otlp::cut(['release' => $options->release, 'environment' => $options->environment], $options->maxValueLength);

        return array_map(static fn(array $chunk): array => ['sdk' => Client::sdk()] + $config + ['aggregates' => $chunk], array_chunk($aggregates, self::MAX_AGGREGATES));
    }

    /**
     * The user, hashed on the device: the first 16 bytes of the SHA-256 of their id (else email,
     * else username), as hex. Never the raw id.
     */
    public static function deviceId(?User $user): ?string
    {
        $id = $user === null ? null : (!Options::empty($user->id) ? $user->id : (!Options::empty($user->email) ? $user->email : $user->username));
        if (Options::empty($id)) {
            return null;
        }

        return substr(hash('sha256', (string) $id), 0, 32);
    }
}
