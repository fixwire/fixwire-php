<?php

declare(strict_types=1);

namespace Fixwire\Tests;

use Fixwire\Client;
use Fixwire\Hub;
use Fixwire\Options;
use Fixwire\Transport\Transport;

/**
 * A fake Fixwire behind the SDK's transport: records each request's decoded body. With a file, it
 * appends them there instead (for child processes).
 */
final class FakeIngest implements Transport
{
    public const DSN = 'http://publickey@ingest.test';

    /** @var list<array{path: string, headers: array<string, string>, body: array<string, mixed>}> */
    public array $received = [];

    /** @var \Closure(int, string): array{0: int, 1: array<string, string>} */
    public \Closure $answer;

    public function __construct(private ?string $file = null)
    {
        $this->answer = static fn(int $n, string $path): array => [200, []];
    }

    public function send(string $url, string $body, array $headers): array
    {
        $json = ($headers['Content-Encoding'] ?? '') === 'gzip' ? gzdecode($body) : $body;
        $path = rawurldecode((string) parse_url($url, \PHP_URL_PATH));
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $json, true, 512, \JSON_THROW_ON_ERROR);
        $request = ['path' => $path, 'headers' => $headers, 'body' => $decoded];
        if ($this->file !== null) {
            file_put_contents($this->file, json_encode($request) . "\n", \FILE_APPEND);
        }
        $n = \count($this->received);
        $this->received[] = $request;

        return ($this->answer)($n, $path);
    }

    /** @return list<array{path: string, headers: array<string, string>, body: array<string, mixed>}> */
    public function requests(string $path = ''): array
    {
        return array_values(array_filter($this->received, static fn(array $r): bool => $path === '' || $r['path'] === $path));
    }

    /**
     * A hub whose client sends here, made current.
     *
     * @param array<string, mixed> $options
     */
    public function hub(array $options = []): Hub
    {
        $o = Options::fromArray($options + ['dsn' => self::DSN, 'service_name' => 'shop', 'transport' => $this, 'project_root' => \dirname(__DIR__)]);
        $hub = new Hub(new Client($o));
        Hub::setCurrent($hub);

        return $hub;
    }

    /** @return list<array<string, mixed>> */
    public static function logRecords(array $requests): array
    {
        $out = [];
        foreach ($requests as $r) {
            foreach ($r['body']['resourceLogs'] as $rl) {
                foreach ($rl['scopeLogs'] as $sl) {
                    foreach ($sl['logRecords'] as $rec) {
                        $out[] = $rec;
                    }
                }
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function spans(array $requests): array
    {
        $out = [];
        foreach ($requests as $r) {
            foreach ($r['body']['resourceSpans'] as $rs) {
                foreach ($rs['scopeSpans'] as $ss) {
                    foreach ($ss['spans'] as $s) {
                        $out[] = $s;
                    }
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function resource(array $request): array
    {
        $key = isset($request['body']['resourceLogs']) ? 'resourceLogs' : 'resourceSpans';

        return self::kv($request['body'][$key][0]['resource']['attributes']);
    }

    /**
     * OTLP key-values as plain values.
     *
     * @param list<array{key: string, value: array<string, mixed>}>|null $list
     *
     * @return array<string, mixed>
     */
    public static function kv(?array $list): array
    {
        $out = [];
        foreach ($list ?? [] as $kv) {
            $out[$kv['key']] = self::anyValue($kv['value']);
        }

        return $out;
    }

    /** @param array<string, mixed> $v */
    public static function anyValue(array $v): mixed
    {
        return match (true) {
            \array_key_exists('stringValue', $v) => $v['stringValue'],
            \array_key_exists('boolValue', $v) => $v['boolValue'],
            \array_key_exists('intValue', $v) => (int) $v['intValue'],
            \array_key_exists('doubleValue', $v) => (float) $v['doubleValue'],
            \array_key_exists('arrayValue', $v) => array_map([self::class, 'anyValue'], $v['arrayValue']['values'] ?? []),
            \array_key_exists('kvlistValue', $v) => self::kv($v['kvlistValue']['values'] ?? []),
            default => null,
        };
    }
}
