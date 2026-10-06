<?php

declare(strict_types=1);

namespace Fixwire\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What ends a PHP process (uncaught exceptions, fatal errors) and what serves a web request, in
 * child processes that send over HTTP to a fake Fixwire run by `php -S`.
 */
final class ProcessTest extends TestCase
{
    /** @var list<resource> */
    private array $servers = [];

    private string $log;

    private string $dsn;

    protected function setUp(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'fixwire-ingest');
        $port = $this->serve(__DIR__ . '/fixtures/ingest.php', ['FAKE_INGEST_LOG' => $this->log]);
        $this->dsn = "http://publickey@127.0.0.1:{$port}";
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        @unlink($this->log);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function transports(): iterable
    {
        yield 'curl' => [[]];
        yield 'streams' => [['-d', 'disable_functions=curl_init,curl_exec']];
    }

    /** @param list<string> $php */
    #[DataProvider('transports')]
    public function testSendsUncaughtExceptions(array $php): void
    {
        [$code, $out] = $this->script('uncaught', $php);
        self::assertSame(255, $code, 'PHP still reports it');
        self::assertStringContainsString('Uncaught InvalidArgumentException: amount 500 exceeds the limit', $out);
        $requests = $this->received(1);
        self::assertCount(1, $requests, 'sent once, not again as a fatal error');
        self::assertSame('/v1/logs', $requests[0]['path']);
        self::assertSame('Bearer publickey', $requests[0]['headers']['authorization']);
        $rec = FakeIngest::logRecords($requests)[0];
        self::assertSame(21, $rec['severityNumber']);
        $a = FakeIngest::kv($rec['attributes']);
        self::assertFalse($a['fixwire.handled']);
        $x = $a['fixwire.exceptions'][0];
        self::assertSame('uncaught', $x['mechanism']['type']);
        $top = $x['frames'][array_key_last($x['frames'])];
        self::assertSame(['chargeCard', 'tests/fixtures/script.php', true], [$top['function'], $top['file'], $top['in_app']]);
        self::assertSame(['started', 'E_USER_WARNING: card network slow'], array_column($a['fixwire.breadcrumbs'], 'message'));
    }

    public function testSendsFatalErrors(): void
    {
        [$code, $out] = $this->script('fatal');
        self::assertSame(255, $code);
        self::assertStringContainsString('Allowed memory size', $out);
        $rec = FakeIngest::logRecords($this->received(1))[0];
        $a = FakeIngest::kv($rec['attributes']);
        $x = $a['fixwire.exceptions'][0];
        self::assertSame(['E_ERROR', 'fatal_error', false], [$x['type'], $x['mechanism']['type'], $x['mechanism']['handled']]);
        self::assertStringStartsWith('Allowed memory size of 33554432 bytes exhausted', $x['message']);
        self::assertSame('tests/fixtures/script.php', $x['frames'][0]['file']);
        self::assertSame(21, $rec['severityNumber']);
    }

    public function testSendsWhatWasCapturedAtExit(): void
    {
        [$code] = $this->script('message');
        self::assertSame(0, $code);
        $rec = FakeIngest::logRecords($this->received(1))[0];
        self::assertSame('nightly report sent', FakeIngest::anyValue($rec['body']));
    }

    /** @param list<string> $php */
    #[DataProvider('transports')]
    public function testDoesNotFollowRedirects(array $php): void
    {
        // A redirect would take the key to wherever it points: here, the fake Fixwire.
        $port = $this->serve(__DIR__ . '/fixtures/redirect.php', ['REDIRECT_TO' => str_replace('publickey@', '', $this->dsn) . '/v1/logs']);
        [$code] = $this->script('message', $php, "http://publickey@127.0.0.1:{$port}");
        self::assertSame(0, $code);
        self::assertSame('', file_get_contents($this->log));
    }

    /** @param list<string> $php */
    #[DataProvider('transports')]
    public function testReadsLittleOfALongAnswer(array $php): void
    {
        $port = $this->serve(__DIR__ . '/fixtures/huge.php', []);
        [$code, $out] = $this->script('message', ['-d', 'memory_limit=32M', ...$php], "http://publickey@127.0.0.1:{$port}");
        self::assertSame(0, $code, $out);
    }

    public function testTracksTheWebRequestPhpServes(): void
    {
        $port = $this->serve(__DIR__ . '/fixtures/web.php', ['FIXWIRE_DSN' => $this->dsn]);
        $context = stream_context_create(['http' => [
            'header' => "traceparent: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01\r\nCookie: session=secret",
            'ignore_errors' => true,
        ]]);
        self::assertSame('ok', file_get_contents("http://127.0.0.1:{$port}/orders/7?tab=items", false, $context));
        @file_get_contents("http://127.0.0.1:{$port}/boom", false, stream_context_create(['http' => ['ignore_errors' => true]]));
        $requests = $this->received(6); // logs and traces for each, a session each

        $records = FakeIngest::logRecords(array_values(array_filter($requests, static fn(array $r): bool => $r['path'] === '/v1/logs')));
        $byBody = [];
        foreach ($records as $rec) {
            $byBody[$rec['eventName']] = $rec;
        }
        $served = $byBody['fixwire.message'];
        $a = FakeIngest::kv($served['attributes']);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $served['traceId']);
        self::assertSame('GET /orders/7', $a['fixwire.transaction']);
        self::assertSame("http://127.0.0.1:{$port}/orders/7", $a['url.full']);
        self::assertSame('tab=items', $a['url.query']);
        self::assertArrayNotHasKey('http.request.header.cookie', $a);
        $boom = FakeIngest::kv($byBody['exception']['attributes']);
        self::assertSame('the oven is on fire', $boom['exception.message']);
        self::assertSame('GET /boom', $boom['fixwire.transaction']);

        $spans = [];
        foreach ($requests as $r) {
            if ($r['path'] === '/v1/traces') {
                foreach (FakeIngest::spans([$r]) as $s) {
                    $spans[$s['name']] = $s;
                }
            }
        }
        self::assertEqualsCanonicalizing(['GET /orders/7', 'SELECT orders', 'GET /boom'], array_keys($spans));
        $page = $spans['GET /orders/7'];
        self::assertSame('00f067aa0ba902b7', $page['parentSpanId']);
        self::assertSame(201, FakeIngest::kv($page['attributes'])['http.response.status_code']);
        self::assertSame($page['spanId'], $spans['SELECT orders']['parentSpanId']);
        self::assertSame(500, FakeIngest::kv($spans['GET /boom']['attributes'])['http.response.status_code']);
        self::assertSame(2, $spans['GET /boom']['status']['code']);

        $sessions = [];
        foreach ($requests as $r) {
            if ($r['path'] === '/v1/sessions') {
                foreach ($r['body']['aggregates'] as $agg) {
                    $sessions[] = $agg;
                }
            }
        }
        self::assertSame([1, 1], [array_sum(array_column($sessions, 'exited')), array_sum(array_column($sessions, 'crashed'))]);
    }

    /**
     * Runs tests/fixtures/script.php in a child PHP.
     *
     * @param list<string> $php
     *
     * @return array{int, string} its exit code and output
     */
    private function script(string $scenario, array $php = [], ?string $dsn = null): array
    {
        $process = proc_open(
            [\PHP_BINARY, '-d', 'display_errors=stderr', ...$php, __DIR__ . '/fixtures/script.php', $scenario],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            null,
            ['FIXWIRE_DSN' => $dsn ?? $this->dsn] + getenv(),
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);

        return [proc_close($process), $out];
    }

    /**
     * Starts `php -S` on a free port with a router script.
     *
     * @param array<string, string> $env
     */
    private function serve(string $router, array $env): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $server = proc_open(
            [\PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            null,
            $env + getenv(),
        );
        self::assertIsResource($server);
        $this->servers[] = $server;
        for ($i = 0; $i < 100; $i++) {
            $up = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($up !== false) {
                fclose($up);

                return $port;
            }
            usleep(50_000);
        }
        self::fail("php -S did not start on {$port}");
    }

    /**
     * What the fake Fixwire got, once it got at least $count requests (or 5 seconds passed).
     *
     * @return list<array{path: string, headers: array<string, string>, body: array<string, mixed>}>
     */
    private function received(int $count): array
    {
        $requests = [];
        for ($i = 0; $i < 100; $i++) {
            $lines = array_filter(explode("\n", (string) file_get_contents($this->log)));
            $requests = array_map(static fn(string $l): array => json_decode($l, true, 512, \JSON_THROW_ON_ERROR), array_values($lines));
            if (\count($requests) >= $count) {
                usleep(100_000); // anything after it, too

                return array_map(static fn(string $l): array => json_decode($l, true, 512, \JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", (string) file_get_contents($this->log)))));
            }
            usleep(50_000);
        }

        return $requests;
    }
}
