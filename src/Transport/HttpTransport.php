<?php

declare(strict_types=1);

namespace Fixwire\Transport;

/**
 * Sends with curl when it is there, else with PHP's streams. Redirects are not followed (the key
 * must not go to another host), and the answer's body is not kept.
 */
final class HttpTransport implements Transport
{
    /** The bytes of an answer's body read before the transfer is cut short. */
    private const MAX_BODY = 64 * 1024;

    public function __construct(private float $timeout = 2.0) {}

    public function send(string $url, string $body, array $headers): array
    {
        return \function_exists('curl_init') ? $this->curl($url, $body, $headers) : $this->stream($url, $body, $headers);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: array<string, string>}
     */
    private function curl(string $url, string $body, array $headers): array
    {
        $answer = [];
        $read = 0;
        $c = curl_init($url);
        if ($c === false) {
            return [0, []];
        }
        curl_setopt_array($c, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_HTTPHEADER => array_map(static fn(string $k, string $v): string => "{$k}: {$v}", array_keys($headers), $headers),
            // The body is thrown away; past MAX_BODY, taking less than given ends the transfer.
            \CURLOPT_WRITEFUNCTION => static function ($c, string $data) use (&$read): int {
                $read += \strlen($data);

                return $read > self::MAX_BODY ? 0 : \strlen($data);
            },
            \CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            \CURLOPT_CONNECTTIMEOUT_MS => (int) min($this->timeout * 1000, 1000),
            \CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$answer): int {
                $parts = explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $answer[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return \strlen($line);
            },
        ]);
        $ok = curl_exec($c);
        if ($ok === false && $read <= self::MAX_BODY) {
            return [0, ['error' => curl_error($c)]]; // no answer: why, for the debug log
        }

        return [(int) curl_getinfo($c, \CURLINFO_RESPONSE_CODE), $answer];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: array<string, string>}
     */
    private function stream(string $url, string $body, array $headers): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", array_map(static fn(string $k, string $v): string => "{$k}: {$v}", array_keys($headers), $headers)),
            'content' => $body,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'follow_location' => 0, // it would send the Authorization header on to the new place
        ]]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            return [0, ['error' => error_get_last()['message'] ?? 'no answer']];
        }
        // The http wrapper keeps the answer's status line and headers here; the body is not read.
        $lines = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        fclose($stream);
        $status = 0;
        $answer = [];
        foreach (\is_array($lines) ? $lines : [] as $line) {
            if (!\is_string($line)) {
                continue;
            }
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $answer = [];
            } elseif (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $answer[strtolower(trim($k))] = trim($v);
            }
        }

        return [$status, $answer];
    }
}
