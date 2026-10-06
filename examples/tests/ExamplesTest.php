<?php

declare(strict_types=1);

namespace Fixwire\Examples\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Runs each example as it would run for real (`php -S`, the CLI) against a fake Fixwire, and
 * checks what it receives, so the examples the docs show keep working.
 */
final class ExamplesTest extends TestCase
{
    /** @var list<resource> */
    private array $servers = [];

    /** @var list<string> */
    private array $files = [];

    private string $ingest;

    private string $dsn;

    protected function setUp(): void
    {
        $this->ingest = $this->tempFile();
        $port = $this->serve(['-S', '127.0.0.1:%d', __DIR__ . '/ingest.php'], ['FAKE_INGEST_LOG' => $this->ingest]);
        $this->dsn = "http://examplekey@127.0.0.1:{$port}";
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testShopApi(): void
    {
        $inventoryLog = $this->tempFile();
        $inventory = $this->serve(['-S', '127.0.0.1:%d', __DIR__ . '/inventory.php'], ['INVENTORY_LOG' => $inventoryLog]);
        $port = $this->serve(['-d', 'display_errors=stderr', '-S', '127.0.0.1:%d', \dirname(__DIR__) . '/shop-api/public/index.php'], [
            'FIXWIRE_DSN' => $this->dsn,
            'INVENTORY_URL' => "http://127.0.0.1:{$inventory}",
        ]);
        $base = "http://127.0.0.1:{$port}";

        $this->assertAnswered(200, $this->http('GET', "{$base}/products/sku_1"));
        $this->assertAnswered(404, $this->http('GET', "{$base}/products/nope"));
        $this->assertAnswered(201, $this->http('POST', "{$base}/orders", '{"sku":"sku_1","card":"4242424242424242"}', ['X-User-Id: user-1']));
        $this->assertAnswered(402, $this->http('POST', "{$base}/orders", '{"sku":"sku_2","card":"4000000000000002"}', ['X-User-Id: user-2']));
        $this->assertAnswered(500, $this->http('GET', "{$base}/admin/report"));
        $requests = $this->received(12); // a trace and a session per request, the two errors

        $events = $this->events($requests);
        self::assertCount(2, $events, 'the 404 is not reported');
        $declined = $events['RuntimeException'];
        self::assertSame('charging order ', substr($declined['exception.message'], 0, 15));
        self::assertSame(['RuntimeException', 'PaymentDeclined'], array_column($declined['fixwire.exceptions'], 'type'));
        self::assertSame('POST /orders', $declined['fixwire.transaction']);
        self::assertSame('user-2', $declined['user.id']);
        self::assertSame(['sku' => 'sku_2'], $declined['fixwire.tags']);
        self::assertSame('sku_2', $declined['fixwire.contexts']['order']['sku']);
        // The log line, then the call to the inventory service.
        self::assertSame(['shop-api', 'http'], array_column($declined['fixwire.breadcrumbs'], 'category'));
        self::assertSame('order received', $declined['fixwire.breadcrumbs'][0]['message']);
        self::assertSame(201, $declined['fixwire.breadcrumbs'][1]['data']['status_code']);
        self::assertTrue($declined['fixwire.handled'] ?? true);
        self::assertStringNotContainsString('4000000000000002', json_encode($requests, \JSON_THROW_ON_ERROR), 'the card stays in the app');

        $crash = $events['DivisionByZeroError'];
        self::assertFalse($crash['fixwire.handled']);
        self::assertSame('psr15', $crash['fixwire.exceptions'][0]['mechanism']['type']);
        self::assertSame('GET /admin/report', $crash['fixwire.transaction']);

        $spans = $this->spans($requests);
        // Both product requests are named after their route, each with its lookup under it.
        $products = $spans['GET /products/{id}'];
        self::assertCount(2, $products);
        self::assertEqualsCanonicalizing(array_column($products, 'spanId'), array_column($spans['SELECT products'], 'parentSpanId'));
        $statuses = array_map(static fn(array $s): int => (int) self::attributes($s)['http.response.status_code'], $products);
        sort($statuses);
        self::assertSame([200, 404], $statuses);
        self::assertSame(2, $spans['GET /admin/report']['status']['code']);
        $order = array_values(array_filter($spans['POST /orders'], static fn(array $s): bool => $s['traceId'] === $declined['traceId']));
        self::assertCount(1, $order);
        $reservations = array_values(array_filter(array_map(static fn(string $l): array => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($inventoryLog))))));
        self::assertCount(2, $reservations);
        foreach ($reservations as $r) {
            self::assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', (string) $r['traceparent'], 'the inventory service continues the trace');
        }
        $reserve = $spans["POST http://127.0.0.1:{$inventory}/reservations"];
        self::assertCount(2, $reserve);
        self::assertContains(substr((string) $reservations[1]['traceparent'], 36, 16), array_column($reserve, 'spanId'));

        $sessions = $this->sessions($requests);
        self::assertSame(['exited' => 3, 'errored' => 1, 'crashed' => 1], $sessions);
    }

    public function testNightlyReport(): void
    {
        [$code, $out] = $this->php([\dirname(__DIR__) . '/nightly-report/report.php']);
        self::assertSame(1, $code);
        self::assertStringContainsString('acme: 2 invoices, 20.00 EUR', $out);
        self::assertStringContainsString('initech: 1 invoices, 43.00 EUR', $out);
        $requests = $this->received(4);

        $checkIns = array_values(array_filter($requests, static fn(array $r): bool => $r['path'] === '/v1/check-ins/nightly-report'));
        self::assertSame(['in_progress', 'error'], array_map(static fn(array $r): string => $r['body']['status'], $checkIns));
        self::assertSame(['type' => 'crontab', 'value' => '0 3 * * *'], $checkIns[0]['body']['monitor_config']['schedule']);
        self::assertSame([10, 30, 'Europe/Berlin'], [
            $checkIns[0]['body']['monitor_config']['checkin_margin'], $checkIns[0]['body']['monitor_config']['max_runtime'], $checkIns[0]['body']['monitor_config']['timezone'],
        ]);
        self::assertSame($checkIns[0]['body']['check_in_id'], $checkIns[1]['body']['check_in_id']);

        $events = $this->events($requests);
        $failure = $events['RuntimeException'];
        self::assertSame(['RuntimeException', 'NoInvoices'], array_column($failure['fixwire.exceptions'], 'type'));
        self::assertSame('building the report for globex', $failure['exception.message']);
        self::assertSame(['account' => 'globex'], $failure['fixwire.tags']);
        $summary = $events['fixwire.message'];
        self::assertSame('1 of 3 reports failed', $summary['body']);
        self::assertArrayNotHasKey('fixwire.tags', $summary, 'each account had its own scope');

        $spans = $this->spans($requests);
        $root = $spans['nightly-report'];
        foreach (['acme', 'globex', 'initech'] as $account) {
            self::assertSame($root['spanId'], $spans["report {$account}"]['parentSpanId']);
        }
        self::assertSame(2, $spans['report globex']['status']['code']);
        self::assertSame($root['traceId'], $failure['traceId']);
    }

    public function testOrderPage(): void
    {
        $port = $this->serve(['-d', 'display_errors=0', '-S', '127.0.0.1:%d', '-t', \dirname(__DIR__) . '/order-page'], ['FIXWIRE_DSN' => $this->dsn]);
        $base = "http://127.0.0.1:{$port}/index.php";
        $traceparent = 'traceparent: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

        $this->assertAnswered(200, $this->http('GET', "{$base}?id=1001", null, [$traceparent]));
        $this->assertAnswered(404, $this->http('GET', "{$base}?id=9"));
        $this->assertAnswered(500, $this->http('GET', "{$base}?id=1002", null, ['Cookie: user_id=user-7']));
        $requests = $this->received(4); // a trace per request, the crash

        $events = $this->events($requests);
        self::assertCount(1, $events);
        $crash = $events['TypeError'];
        self::assertStringStartsWith('count(): Argument #1 ($value) must be of type Countable|array, null given', $crash['exception.message']);
        self::assertFalse($crash['fixwire.handled']);
        self::assertSame('GET /index.php', $crash['fixwire.transaction']);
        self::assertSame('user-7', $crash['user.id']);
        self::assertSame('id=1002', $crash['url.query']);
        self::assertSame(['E_WARNING: Undefined array key "items"'], array_column($crash['fixwire.breadcrumbs'], 'message'));
        $frames = $crash['fixwire.exceptions'][0]['frames'];
        self::assertSame(['order-page/index.php', true], [$frames[array_key_last($frames)]['file'], $frames[array_key_last($frames)]['in_app']], 'relative to the Composer project');

        $pages = $this->spans($requests)['GET /index.php'];
        self::assertCount(3, $pages);
        $statuses = array_map(static fn(array $s): int => (int) self::attributes($s)['http.response.status_code'], $pages);
        sort($statuses);
        self::assertSame([200, 404, 500], $statuses);
        self::assertContains('00f067aa0ba902b7', array_column($pages, 'parentSpanId'), "the first page continues the caller's trace");
    }

    /**
     * The events among the requests, by exception type (or event name), with plain attributes.
     *
     * @param list<array<string, mixed>> $requests
     *
     * @return array<string, array<string, mixed>>
     */
    private function events(array $requests): array
    {
        $out = [];
        foreach ($requests as $r) {
            foreach ($r['body']['resourceLogs'] ?? [] as $rl) {
                foreach ($rl['scopeLogs'] as $sl) {
                    foreach ($sl['logRecords'] as $rec) {
                        $a = self::kv($rec['attributes']);
                        $a['body'] = isset($rec['body']) ? self::value($rec['body']) : null;
                        $a['traceId'] = $rec['traceId'] ?? null;
                        $out[$a['exception.type'] ?? $rec['eventName']] = $a;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The spans among the requests, by name: one span, or a list when several share it.
     *
     * @param list<array<string, mixed>> $requests
     *
     * @return array<string, mixed>
     */
    private function spans(array $requests): array
    {
        $byName = [];
        foreach ($requests as $r) {
            foreach ($r['body']['resourceSpans'] ?? [] as $rs) {
                foreach ($rs['scopeSpans'] as $ss) {
                    foreach ($ss['spans'] as $s) {
                        $byName[$s['name']][] = $s;
                    }
                }
            }
        }

        return array_map(static fn(array $list): array => \count($list) === 1 ? $list[0] : $list, $byName);
    }

    /**
     * Release health: the requests' sessions, summed.
     *
     * @param list<array<string, mixed>> $requests
     *
     * @return array{exited: int, errored: int, crashed: int}
     */
    private function sessions(array $requests): array
    {
        $sum = ['exited' => 0, 'errored' => 0, 'crashed' => 0];
        foreach ($requests as $r) {
            if ($r['path'] === '/v1/sessions') {
                foreach ($r['body']['aggregates'] as $agg) {
                    foreach ($sum as $k => $n) {
                        $sum[$k] = $n + ($agg[$k] ?? 0);
                    }
                }
            }
        }

        return $sum;
    }

    /**
     * @param array<string, mixed> $span
     *
     * @return array<string, mixed>
     */
    private static function attributes(array $span): array
    {
        return self::kv($span['attributes'] ?? []);
    }

    /**
     * @param list<array{key: string, value: array<string, mixed>}> $list
     *
     * @return array<string, mixed>
     */
    private static function kv(array $list): array
    {
        $out = [];
        foreach ($list as $kv) {
            $out[$kv['key']] = self::value($kv['value']);
        }

        return $out;
    }

    /** @param array<string, mixed> $v */
    private static function value(array $v): mixed
    {
        return match (true) {
            \array_key_exists('stringValue', $v) => $v['stringValue'],
            \array_key_exists('boolValue', $v) => $v['boolValue'],
            \array_key_exists('intValue', $v) => (int) $v['intValue'],
            \array_key_exists('doubleValue', $v) => (float) $v['doubleValue'],
            \array_key_exists('arrayValue', $v) => array_map(self::value(...), $v['arrayValue']['values'] ?? []),
            \array_key_exists('kvlistValue', $v) => self::kv($v['kvlistValue']['values'] ?? []),
            default => null,
        };
    }

    /**
     * Fails with what the app answered and what it reported, when the status isn't the one expected.
     *
     * @param array{int, string} $answer
     */
    private function assertAnswered(int $status, array $answer): void
    {
        if ($answer[0] === $status) {
            $this->addToAssertionCount(1);

            return;
        }
        usleep(500_000); // the app sends what it captured once it has answered
        $reported = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($this->ingest))) as $line) {
            foreach (json_decode($line, true)['body']['resourceLogs'] ?? [] as $rl) {
                foreach ($rl['scopeLogs'] as $sl) {
                    foreach ($sl['logRecords'] as $rec) {
                        $a = array_column($rec['attributes'], 'value', 'key');
                        $reported[] = ($a['exception.type']['stringValue'] ?? 'message') . ': ' . ($a['exception.message']['stringValue'] ?? '');
                    }
                }
            }
        }
        self::fail("answered {$answer[0]}, not {$status}: " . substr($answer[1], 0, 500) . "\nreported: " . implode("\n          ", $reported));
    }

    /**
     * @param list<string> $headers
     *
     * @return array{int, string} the status and the body
     */
    private function http(string $method, string $url, ?string $body = null, array $headers = []): array
    {
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $stream = fopen($url, 'rb', false, $context);
        self::assertNotFalse($stream, "{$method} {$url}");
        $status = 0;
        foreach (stream_get_meta_data($stream)['wrapper_data'] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        $answer = (string) stream_get_contents($stream);
        fclose($stream);

        return [$status, $answer];
    }

    /**
     * Runs PHP with these arguments.
     *
     * @param list<string> $args
     *
     * @return array{int, string} its exit code and output
     */
    private function php(array $args): array
    {
        $process = proc_open([\PHP_BINARY, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['FIXWIRE_DSN' => $this->dsn] + getenv());
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return [proc_close($process), $out];
    }

    /**
     * Starts PHP's built-in server on a free port (%d in the arguments).
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    private function serve(array $args, array $env): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $server = proc_open(
            [\PHP_BINARY, ...array_map(static fn(string $a): string => \sprintf($a, $port), $args)],
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

    private function tempFile(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'fixwire-example');
        $this->files[] = $file;

        return $file;
    }

    /**
     * What the fake Fixwire got, once it got at least $count requests (or 10 seconds passed).
     *
     * @return list<array<string, mixed>>
     */
    private function received(int $count): array
    {
        $read = fn(): array => array_map(
            static fn(string $l): array => json_decode($l, true, 512, \JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) file_get_contents($this->ingest)))),
        );
        for ($i = 0; $i < 200 && \count($read()) < $count; $i++) {
            usleep(50_000);
        }
        usleep(200_000); // anything after it, too

        return $read();
    }
}
