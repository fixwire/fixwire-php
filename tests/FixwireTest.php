<?php

declare(strict_types=1);

namespace Fixwire\Tests;

use Fixwire\Breadcrumb;
use Fixwire\Budget;
use Fixwire\CheckInStatus;
use Fixwire\Client;
use Fixwire\Dsn;
use Fixwire\Event;
use Fixwire\ExceptionValue;
use Fixwire\Feedback;
use Fixwire\Frame;
use Fixwire\Hub;
use Fixwire\Level;
use Fixwire\MonitorConfig;
use Fixwire\Options;
use Fixwire\Scope;
use Fixwire\Sessions;
use Fixwire\Span;
use Fixwire\User;
use PHPUnit\Framework\TestCase;

final class CartException extends \RuntimeException {}

final class Cart
{
    public static function chargeCard(int $amount): void
    {
        if ($amount > 100) {
            throw new \InvalidArgumentException("amount {$amount} exceeds the limit");
        }
    }

    public function checkout(int $amount): void
    {
        try {
            self::chargeCard($amount);
        } catch (\InvalidArgumentException $e) {
            throw new CartException('checkout failed', 0, $e);
        }
    }
}

final class FixwireTest extends TestCase
{
    protected function setUp(): void
    {
        Client::resetRateLimits();
    }

    public function testParsesDsns(): void
    {
        $d = Dsn::parse('https://fw_pk_live_abc@ingest.fixwire.io');
        self::assertSame('fw_pk_live_abc', $d->key);
        self::assertSame('https://ingest.fixwire.io', $d->baseUrl);
        self::assertSame('http://127.0.0.1:9000/v1/logs', Dsn::parse('http://k@127.0.0.1:9000/')->url('/v1/logs'));
        self::assertSame('https://self.example.com/fixwire', Dsn::parse(' https://k@self.example.com/fixwire ')->baseUrl);
        foreach (['', 'ingest.fixwire.io', 'https://ingest.fixwire.io', 'ftp://k@host', 'https://@host'] as $bad) {
            try {
                Dsn::parse($bad);
                self::fail("accepted {$bad}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRejectsUnknownOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Options::fromArray(['dsm' => 'typo']);
    }

    public function testDoesNothingWithoutADsn(): void
    {
        $o = Options::fromArray(['dsn' => '']);
        $client = new Client($o);
        if (getenv('FIXWIRE_DSN') === false) {
            self::assertFalse($client->isEnabled());
        }
        self::assertNull((new Hub(new Client(Options::fromArray([]))))->captureMessage('x') ?? null);
    }

    public function testCapturesExceptionsWithTheirPreviousOnes(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['release' => 'shop@1.2.0', 'environment' => 'staging', 'server_name' => 'web-1']);
        $hub->getScope()->setUser(new User(id: 'user-1', username: 'ada'))->setTag('plan', 'team')->setContext('order', ['id' => 42]);
        $hub->addBreadcrumb(new Breadcrumb('cart', 'checkout started'));

        try {
            (new Cart())->checkout(500);
            self::fail('no exception');
        } catch (CartException $e) {
            $id = $hub->captureException($e);
        }
        self::assertSame(32, \strlen((string) $id));
        self::assertSame([], $ingest->requests(), 'sent only when the client flushes');
        self::assertTrue($hub->flush());

        $reqs = $ingest->requests('/v1/logs');
        self::assertCount(1, $reqs);
        self::assertSame('Bearer publickey', $reqs[0]['headers']['Authorization']);
        self::assertSame('gzip', $reqs[0]['headers']['Content-Encoding']);
        self::assertSame('fixwire.php/' . Client::SDK_VERSION, $reqs[0]['headers']['User-Agent']);
        $res = FakeIngest::resource($reqs[0]);
        self::assertSame(['shop', 'shop@1.2.0', 'staging', 'web-1', 'php'], [
            $res['service.name'], $res['service.version'], $res['deployment.environment.name'], $res['host.name'], $res['telemetry.sdk.language'],
        ]);

        $rec = FakeIngest::logRecords($reqs)[0];
        self::assertSame('exception', $rec['eventName']);
        self::assertSame(17, $rec['severityNumber']);
        $a = FakeIngest::kv($rec['attributes']);
        self::assertSame($id, $a['fixwire.event_id']);
        self::assertSame(CartException::class, $a['exception.type']);
        self::assertSame('checkout failed', $a['exception.message']);
        self::assertSame('user-1', $a['user.id']);
        self::assertSame('ada', $a['user.name']);
        self::assertSame(['plan' => 'team'], $a['fixwire.tags']);
        self::assertSame(['order' => ['id' => 42]], $a['fixwire.contexts']);
        self::assertSame('checkout started', $a['fixwire.breadcrumbs'][0]['message']);
        self::assertArrayNotHasKey('fixwire.handled', $a);

        $chain = $a['fixwire.exceptions'];
        self::assertCount(2, $chain);
        self::assertSame('generic', $chain[0]['mechanism']['type']);
        self::assertSame('chained', $chain[1]['mechanism']['type']);
        self::assertSame('InvalidArgumentException', $chain[1]['type']);
        $thrower = $chain[1]['frames'][array_key_last($chain[1]['frames'])];
        self::assertSame('chargeCard', $thrower['function']);
        self::assertSame(Cart::class, $thrower['module']);
        self::assertSame('tests/FixwireTest.php', $thrower['file'], 'relative to the project root');
        self::assertTrue($thrower['in_app']);
        self::assertStringContainsString('exceeds the limit', $thrower['context_line']);
        self::assertCount(5, $thrower['pre_context']);
        $outer = $chain[0]['frames'];
        self::assertSame('checkout', $outer[array_key_last($outer)]['function']);
        foreach ($outer as $f) {
            if (str_contains((string) $f['file'], 'vendor/')) {
                self::assertFalse($f['in_app'], (string) $f['file']);
            }
        }
    }

    public function testNamesClosuresWithoutTheirPlace(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub();
        $fail = static function (): never {
            throw new \LogicException('in a closure');
        };
        try {
            $fail();
        } catch (\LogicException $e) {
            $hub->captureException($e);
        }
        $hub->flush();
        $frames = FakeIngest::kv(FakeIngest::logRecords($ingest->requests())[0]['attributes'])['fixwire.exceptions'][0]['frames'];
        self::assertSame('{closure}', $frames[array_key_last($frames)]['function']);
    }

    public function testCapturesMessagesAtTheirLevel(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub();
        $hub->captureMessage('disk almost full');
        $hub->withScope(static function (Scope $s) use ($hub): void {
            $s->setLevel(Level::Warning);
            $hub->captureMessage('slow query');
        });
        $hub->captureMessage('on fire', Level::Fatal);
        $hub->flush();
        $recs = FakeIngest::logRecords($ingest->requests('/v1/logs'));
        self::assertCount(3, $recs);
        self::assertCount(1, $ingest->requests('/v1/logs'), 'one request for all of them');
        self::assertSame('fixwire.message', $recs[0]['eventName']);
        self::assertSame('disk almost full', FakeIngest::anyValue($recs[0]['body']));
        self::assertSame([9, 13, 21], array_column($recs, 'severityNumber'));
    }

    public function testBeforeSendChangesOrDrops(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['before_send' => static function (Event $e): ?Event {
            if (str_contains((string) $e->message, 'noise')) {
                return null;
            }
            $e->tags['seen'] = 'yes';

            return $e;
        }]);
        self::assertNull($hub->captureMessage('noise'));
        self::assertNotNull($hub->captureMessage('signal'));
        $hub->flush();
        $recs = FakeIngest::logRecords($ingest->requests());
        self::assertCount(1, $recs);
        self::assertSame(['seen' => 'yes'], FakeIngest::kv($recs[0]['attributes'])['fixwire.tags']);
    }

    public function testTracesSegmentsWithTheirSpans(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['traces_sample_rate' => 1.0]);
        $root = $hub->startSpan('POST /checkout', 'http.server', ['http.request.method' => 'POST']);
        self::assertSame($root, $hub->getSpan());
        $result = \Fixwire\trace(static function (Span $q) use ($hub, $root): string {
            self::assertSame($q, $hub->getSpan());
            self::assertSame($root->spanId, $q->parentSpanId);
            $q->setError('deadlock');

            return 'rows';
        }, 'SELECT carts', 'db.query');
        self::assertSame('rows', $result);
        self::assertSame($root, $hub->getSpan());
        $hub->captureMessage('linked');
        $root->finish();
        self::assertNull($hub->getSpan());
        $hub->flush();

        $spans = [];
        foreach (FakeIngest::spans($ingest->requests('/v1/traces')) as $s) {
            $spans[$s['name']] = $s;
        }
        self::assertCount(2, $spans);
        $r = $spans['POST /checkout'];
        self::assertSame(2, $r['kind']);
        self::assertSame(0x101, $r['flags']);
        self::assertArrayNotHasKey('parentSpanId', $r);
        self::assertSame('http.server', FakeIngest::kv($r['attributes'])['fixwire.op']);
        self::assertSame('POST', FakeIngest::kv($r['attributes'])['http.request.method']);
        $q = $spans['SELECT carts'];
        self::assertSame(3, $q['kind']);
        self::assertSame(['code' => 2, 'message' => 'deadlock'], $q['status']);
        $rec = FakeIngest::logRecords($ingest->requests('/v1/logs'))[0];
        self::assertSame($root->traceId, $rec['traceId']);
        self::assertSame($root->spanId, $rec['spanId']);
        self::assertSame('POST /checkout', FakeIngest::kv($rec['attributes'])['fixwire.transaction']);
    }

    public function testContinuesCallersTraces(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['traces_sample_rate' => 0.0]);
        $s = $hub->continueTrace('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', 'fw=1', 'user=1', 'GET /');
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $s->traceId);
        self::assertSame('00f067aa0ba902b7', $s->parentSpanId);
        self::assertTrue($s->sampled, "the caller's decision holds");
        self::assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-' . $s->spanId . '-01', $s->traceparent());
        self::assertSame(['fw=1', 'user=1'], [$s->tracestate, $s->baggage]);
        $s->finish();
        $hub->flush();
        self::assertSame(0x301, FakeIngest::spans($ingest->requests('/v1/traces'))[0]['flags']);
        $u = $hub->continueTrace('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00', null, null, 'GET /');
        self::assertFalse($u->sampled);
        $u->finish();
        foreach ([
            '', '00-xyz-00f067aa0ba902b7-01', '00-00000000000000000000000000000000-00f067aa0ba902b7-01',
            'ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', '00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01',
        ] as $bad) {
            self::assertNull(Span::parseTraceparent($bad), $bad);
        }
    }

    public function testSamplesTracesByTheSharedRule(): void
    {
        self::assertTrue(Span::sample('4bf92f3577b34da6ffffffffffffffff', 0.01));
        self::assertFalse(Span::sample('4bf92f3577b34da6a000000000000000', 0.5));
        self::assertTrue(Span::sample('4bf92f3577b34da6a080000000000000', 0.5));
        self::assertFalse(Span::sample('4bf92f3577b34da6a07ffffffffff000', 0.5));
        self::assertTrue(Span::sample('4bf92f3577b34da6a000000000000000', 1.0));
        self::assertFalse(Span::sample('4bf92f3577b34da6ffffffffffffffff', 0.0));
    }

    public function testCountsRequestSessionsWhenAsked(): void
    {
        $ingest = new FakeIngest();
        self::assertNull($ingest->hub(['release' => 'shop@1.2.0'])->getClient()?->sessions(), 'off by default');
        $hub = $ingest->hub(['release' => 'shop@1.2.0', 'auto_session_tracking' => true]);
        foreach (['ok', 'ok', 'handled', 'crash'] as $i => $outcome) {
            $hub->withScope(static function (Scope $s) use ($hub, $i, $outcome): void {
                $s->setUser(new User('user-' . ($i % 2)));
                $end = $hub->startRequestSession();
                if ($outcome === 'handled') {
                    $hub->captureException(new \RuntimeException('x'));
                } elseif ($outcome === 'crash') {
                    $hub->captureException(new \RuntimeException('y'), 'uncaught', false);
                }
                $end();
                $end(); // once
            });
        }
        $hub->flush();
        $body = $ingest->requests('/v1/sessions')[0]['body'];
        self::assertSame('shop@1.2.0', $body['release']);
        self::assertSame('fixwire.php', $body['sdk']['name']);
        $sum = static fn(string $k): int => array_sum(array_column($body['aggregates'], $k));
        self::assertSame([2, 1, 1], [$sum('exited'), $sum('errored'), $sum('crashed')]);
        self::assertCount(2, array_unique(array_column($body['aggregates'], 'did')));
        self::assertContains(Sessions::deviceId(new User('user-0')), array_column($body['aggregates'], 'did'));
        self::assertSame(32, \strlen((string) Sessions::deviceId(new User('user-0'))));
    }

    public function testSendsCheckInsAtOnceAndFeedbackLater(): void
    {
        $ingest = new FakeIngest();
        $ingest->hub(['release' => 'shop@1.2.0']);
        try {
            \Fixwire\withMonitor('nightly report', MonitorConfig::crontab('0 3 * * *', checkInMargin: 5, timezone: 'Europe/Berlin'), static function () use ($ingest): never {
                self::assertCount(1, $ingest->requests(), 'in_progress is sent when the run starts');
                throw new \RuntimeException('no data');
            });
        } catch (\RuntimeException) {
        }
        $checkIns = $ingest->requests('/v1/check-ins/nightly report');
        self::assertCount(2, $checkIns);
        [$start, $end] = [$checkIns[0]['body'], $checkIns[1]['body']];
        self::assertSame(CheckInStatus::InProgress->value, $start['status']);
        self::assertSame(['schedule' => ['type' => 'crontab', 'value' => '0 3 * * *'], 'checkin_margin' => 5, 'timezone' => 'Europe/Berlin'], $start['monitor_config']);
        self::assertSame('error', $end['status']);
        self::assertSame($start['check_in_id'], $end['check_in_id']);
        self::assertArrayHasKey('duration', $end);
        self::assertArrayNotHasKey('monitor_config', $end);

        self::assertNotNull(\Fixwire\captureFeedback(new Feedback('The refund was wrong', score: -3, traceId: '4bf92f3577b34da6a3ce929d0e0e4736')));
        self::assertNull(\Fixwire\captureFeedback(new Feedback('  ')));
        self::assertSame([], $ingest->requests('/v1/feedback'));
        \Fixwire\flush();
        $fb = $ingest->requests('/v1/feedback')[0]['body'];
        self::assertSame(-1, $fb['score'] <=> 0);
        self::assertSame(-1.0, (float) $fb['score']);
        self::assertSame(['The refund was wrong', '4bf92f3577b34da6a3ce929d0e0e4736', 'api', 'shop@1.2.0'], [$fb['message'], $fb['trace_id'], $fb['source'], $fb['release']]);
    }

    public function testRedactsSecretsBeforeTheyLeave(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub();
        $hub->getScope()->setContext('payment', ['card' => '4111 1111 1111 1111', 'password' => 'hunter22', 'note' => 'ok']);
        $hub->captureMessage('login for ada@example.com failed');
        $off = new FakeIngest();
        $off->hub(['redact' => false])->captureMessage('login for ada@example.com failed');
        $hub->flush();
        Hub::current()->flush();

        $rec = FakeIngest::logRecords($ingest->requests())[0];
        self::assertSame('login for [REDACTED:email] failed', FakeIngest::anyValue($rec['body']));
        $payment = FakeIngest::kv($rec['attributes'])['fixwire.contexts']['payment'];
        self::assertSame(['card' => '[REDACTED:credit_card]', 'password' => '[Filtered]', 'note' => 'ok'], $payment);
        self::assertSame('login for ada@example.com failed', FakeIngest::anyValue(FakeIngest::logRecords($off->requests())[0]['body']));
    }

    public function testRetriesOnceAndHonoursRateLimits(): void
    {
        $ingest = new FakeIngest();
        $ingest->answer = static fn(int $n, string $path): array => match ($n) {
            0 => [503, []], // retried
            1 => [200, ['fixwire-rate-limits' => '3600:error']],
            default => [200, []],
        };
        $hub = $ingest->hub();
        $hub->captureMessage('first');
        self::assertTrue($hub->flush());
        self::assertCount(2, $ingest->requests('/v1/logs'));
        // Errors are paused for an hour; feedback isn't.
        $hub->captureMessage('dropped');
        $hub->captureFeedback(new Feedback(score: 1));
        self::assertFalse($hub->flush());
        self::assertCount(2, $ingest->requests('/v1/logs'));
        self::assertCount(1, $ingest->requests('/v1/feedback'));
    }

    public function testDropsRefusedRequests(): void
    {
        $ingest = new FakeIngest();
        $ingest->answer = static fn(): array => [400, []];
        $hub = $ingest->hub();
        $hub->captureMessage('bad');
        self::assertFalse($hub->flush());
        self::assertCount(1, $ingest->requests());
    }

    public function testBudgetsCrashLoops(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['error_budget' => ['per_issue_burst' => 3]]);
        $sent = 0;
        for ($i = 0; $i < 20; $i++) {
            if ($hub->captureMessage('order ' . (1000 + $i) . ' failed') !== null) {
                $sent++;
            }
        }
        self::assertSame(3, $sent);
        self::assertNotNull($hub->captureMessage('another issue'));
        $probe = new Event();
        $probe->message = 'order 1 failed';
        $hub->getClient()?->budget()->age(Budget::issueOf($probe), 60);
        self::assertNotNull($hub->captureMessage('order 2000 failed'));
        $hub->flush();
        $recs = FakeIngest::logRecords($ingest->requests());
        self::assertSame(17, FakeIngest::kv($recs[array_key_last($recs)]['attributes'])['fixwire.suppressed']);

        $at = static function (string $function): Event {
            $e = new Event();
            $e->exceptions = [new ExceptionValue('x', '', frames: [new Frame($function, 'Shop\App', inApp: true)])];

            return $e;
        };
        self::assertNotSame(Budget::issueOf($at('a')), Budget::issueOf($at('b')));
        $m1 = new Event();
        $m1->message = 'user ada@example.com: 3 retries';
        $m2 = new Event();
        $m2->message = 'user bob@example.org: 12 retries';
        self::assertSame(Budget::issueOf($m1), Budget::issueOf($m2));
    }

    public function testTurnsPhpWarningsIntoBreadcrumbs(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub();
        $reporting = error_reporting(\E_ALL); // PHPUnit narrows it
        try {
            \Fixwire\ErrorHandler::onError(\E_WARNING, 'Undefined array key "sku"', __FILE__, __LINE__);
            error_reporting(\E_ALL & ~\E_NOTICE);
            \Fixwire\ErrorHandler::onError(\E_NOTICE, 'not reported', __FILE__, __LINE__);
        } finally {
            error_reporting($reporting);
        }
        $hub->captureMessage('after');
        $hub->flush();
        $crumbs = FakeIngest::kv(FakeIngest::logRecords($ingest->requests())[0]['attributes'])['fixwire.breadcrumbs'];
        self::assertCount(1, $crumbs);
        $crumb = $crumbs[0];
        self::assertSame('E_WARNING: Undefined array key "sku"', $crumb['message']);
        self::assertSame('warning', $crumb['level']);
        self::assertSame('php', $crumb['category']);
    }
}
