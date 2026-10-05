<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * @internal release health: each request is a session, counted per minute and user, and sent
 * with the rest when the client flushes (sdks/PROTOCOL.md §5)
 */
final class Sessions
{
    /** @var array<string, array{minute: int, did: ?string, counts: array{int, int, int}}> exited, errored, crashed */
    private array $buckets = [];

    public function record(string $status, ?string $did, float $at): void
    {
        $minute = (int) (floor($at / 60) * 60);
        $key = $minute . '|' . ($did ?? '');
        $this->buckets[$key] ??= ['minute' => $minute, 'did' => $did, 'counts' => [0, 0, 0]];
        $this->buckets[$key]['counts'][$status === 'crashed' ? 2 : ($status === 'errored' ? 1 : 0)]++;
    }

    /**
     * What was counted, as a /v1/sessions body, or null; forgets it.
     *
     * @return array<string, mixed>|null
     */
    public function take(Options $options): ?array
    {
        if ($this->buckets === []) {
            return null;
        }
        $aggregates = [];
        foreach ($this->buckets as $b) {
            $a = ['started' => gmdate('Y-m-d\TH:i:s\Z', $b['minute'])];
            if ($b['did'] !== null) {
                $a['did'] = $b['did'];
            }
            [$a['exited'], $a['errored'], $a['crashed']] = $b['counts'];
            $aggregates[] = $a;
        }
        $this->buckets = [];

        return [
            'sdk' => Client::sdk(),
            'release' => $options->release,
            'environment' => $options->environment,
            'aggregates' => $aggregates,
        ];
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
