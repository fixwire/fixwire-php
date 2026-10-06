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
use Fixwire\Frames;
use Fixwire\Hub;
use Fixwire\Level;
use Fixwire\MonitorConfig;
use Fixwire\Options;
use Fixwire\Scope;
use Fixwire\Sessions;
use Fixwire\Span;
use Fixwire\SpanKind;
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

    public function testReadsTheEnvironmentAsDotenvLoadersFillIt(): void
    {
        $_SERVER['FIXWIRE_RELEASE'] = 'shop@2.0.0';
        $_ENV['FIXWIRE_ENVIRONMENT'] = 'staging';
        $_SERVER['FIXWIRE_DEBUG'] = 'true';
        try {
            $o = Options::fromArray([]);
            $o->applyDefaults();
            self::assertSame(['shop@2.0.0', 'staging', 'shop', true], [$o->release, $o->environment, $o->serviceName, $o->debug]);
        } finally {
            unset($_SERVER['FIXWIRE_RELEASE'], $_ENV['FIXWIRE_ENVIRONMENT'], $_SERVER['FIXWIRE_DEBUG']);
        }
    }

    public function testFramePathsUseForwardSlashesOnEveryOs(): void
    {
        $o = Options::fromArray(['project_root' => 'C:\\www\\shop', 'context_lines' => 0]);
        $inApp = Frames::at('Shop\\Cart', 'checkout', 'C:\\www\\shop\\src\\Cart.php', 12, $o);
        $outside = Frames::at(null, 'main', 'D:\\tools\\run.php', 3, $o);
        self::assertSame(['src/Cart.php', 'D:/tools/run.php'], [$inApp->file, $outside->file]);
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

    public function testDatesSpansTimedElsewhereAndNestsScopes(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['traces_sample_rate' => 1.0]);
        $root = $hub->startSpan('nightly', 'task');
        // A query that ran already, dated back (as framework integrations do).
        Span::start($hub, 'SELECT 1', 'db.query', [], SpanKind::Client, current: false, startTime: 1791200000.5)->finish(1791200000.75);
        self::assertSame($root, $hub->getSpan(), 'not made current');
        $root->finish();

        $job = $hub->pushScope();
        $job->setTag('job', 'export');
        $hub->captureMessage('in the job');
        $hub->popScope();
        $hub->popScope(); // the outermost scope stays
        $hub->captureMessage('after it');
        $hub->flush();

        $query = array_column(FakeIngest::spans($ingest->requests('/v1/traces')), null, 'name')['SELECT 1'];
        self::assertSame(['1791200000500000000', '1791200000750000000'], [$query['startTimeUnixNano'], $query['endTimeUnixNano']]);
        self::assertSame($root->spanId, $query['parentSpanId']);
        $tags = array_map(static fn(array $r): mixed => FakeIngest::kv($r['attributes'])['fixwire.tags'] ?? null, FakeIngest::logRecords($ingest->requests('/v1/logs')));
        self::assertSame([['job' => 'export'], null], $tags);
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

    public function testAsksForTheUserWhenItIsNeeded(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['release' => 'shop@1.0.0', 'auto_session_tracking' => true]);
        $signedIn = null;
        $hub->getScope()->userProvider = static function () use (&$signedIn): ?User {
            return $signedIn === null ? null : new User($signedIn);
        };
        $end = $hub->startRequestSession();
        $hub->captureMessage('before signing in');
        $signedIn = 'user-9';
        $hub->captureMessage('after');
        $hub->getScope()->setUser(new User('user-1'));
        $hub->captureMessage('set explicitly');
        $hub->getScope()->setUser(null);
        $end();
        $hub->flush();

        $users = array_map(static fn(array $r): mixed => FakeIngest::kv($r['attributes'])['user.id'] ?? null, FakeIngest::logRecords($ingest->requests('/v1/logs')));
        self::assertSame([null, 'user-9', 'user-1'], $users);
        self::assertSame(Sessions::deviceId(new User('user-9')), $ingest->requests('/v1/sessions')[0]['body']['aggregates'][0]['did']);
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

    public function testNeverThrowsIntoTheApp(): void
    {
        // A before_send that returns the wrong thing, and a transport that throws.
        $ingest = new FakeIngest();
        $ingest->answer = static function (): never {
            throw new \RuntimeException('the network is gone');
        };
        $hub = $ingest->hub([
            'before_send' => static fn(Event $e): mixed => str_contains((string) $e->message, 'odd') ? 'not an event' : $e,
            'release' => 'shop@1.0.0',
        ]);
        self::assertNull($hub->captureMessage('an odd one'));
        self::assertNotNull($hub->captureMessage('a fine one'));
        self::assertFalse($hub->flush(), 'reported, not thrown');
        self::assertNull(\Fixwire\captureCheckIn(new \Fixwire\CheckIn('nightly', CheckInStatus::InProgress)));
        self::assertSame('done', \Fixwire\withMonitor('nightly', null, static fn(): string => 'done'));
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

    public function testBudgetsHugeMessagesQuickly(): void
    {
        // The email part of the budget's pattern is quadratic: 300 kB of these took seconds.
        $start = microtime(true);
        foreach (['a@', 'a@a', '@.', '@a.'] as $unit) {
            $e = new Event();
            $e->message = str_repeat($unit, 100_000);
            Budget::issueOf($e);
        }
        self::assertLessThan(1.0, microtime(true) - $start);
        $long = new Event();
        $long->message = str_repeat('x', 2000) . ' order 1';
        $other = new Event();
        $other->message = str_repeat('x', 2000) . ' cart 2';
        self::assertSame(Budget::issueOf($long), Budget::issueOf($other), 'the start of a message tells its issue');
    }

    public function testBudgetKeepsIssuesWhoseHashLooksLikeANumber(): void
    {
        // PHP makes such keys integers; evicting the oldest must not renumber the rest.
        $budget = new Budget(['per_issue_burst' => 1, 'per_issue_per_minute' => 0, 'per_minute' => 1e6]);
        $now = microtime(true);
        for ($i = 0; $i < 1024; $i++) {
            self::assertSame(0, $budget->allow((string) (1_000_000_000_000_000 + $i), $now));
        }
        self::assertSame(0, $budget->allow('fresh', $now)); // evicts the oldest
        self::assertSame(-1, $budget->allow('1000000000000005', $now), 'its burst is spent');
    }

    public function testKeepsTheLastBreadcrumbsCheaply(): void
    {
        $scope = new Scope();
        for ($i = 0; $i < 250; $i++) {
            $scope->addBreadcrumb(new Breadcrumb('n', (string) $i), 100);
        }
        $e = new Event();
        $scope->applyTo($e, null);
        self::assertSame(range(150, 249), array_map(static fn(Breadcrumb $b): int => (int) $b->message, $e->breadcrumbs));

        $start = microtime(true);
        $big = new Scope();
        for ($i = 0; $i < 100_000; $i++) {
            $big->addBreadcrumb(new Breadcrumb('n', 'x'), 10_000);
        }
        self::assertLessThan(1.0, microtime(true) - $start, 'adding one does not copy them all');
    }

    public function testIgnoresABeforeBreadcrumbThatReturnsSomethingElse(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['before_breadcrumb' => static fn(Breadcrumb $b): mixed => 'not a breadcrumb']);
        $hub->addBreadcrumb(new Breadcrumb('cart', 'loaded'));
        $hub->captureMessage('after');
        $hub->flush();
        self::assertArrayNotHasKey('fixwire.breadcrumbs', FakeIngest::kv(FakeIngest::logRecords($ingest->requests())[0]['attributes']));
    }

    public function testCapsPausesAndHonoursRetryAfterOn5xx(): void
    {
        // Seconds or an HTTP date, up to a day; broken ones are ignored.
        foreach ([
            [429, ['retry-after' => '99999999999999'], ['' => 86_400]],
            [429, ['retry-after' => '86401'], ['' => 86_400]],
            [429, ['retry-after' => '86400'], ['' => 86_400]],
            [429, ['retry-after' => gmdate('D, d M Y H:i:s \G\M\T', time() + 600)], ['' => 600]],
            [429, ['retry-after' => gmdate('D, d M Y H:i:s \G\M\T', time() + 200_000)], ['' => 86_400]],
            [429, ['retry-after' => gmdate('D, d M Y H:i:s \G\M\T', time() - 600)], ['' => 60]],
            [429, ['retry-after' => '5'], ['' => 60]],
            [429, ['retry-after' => 'soon'], ['' => 60]],
            [429, [], ['' => 60]],
            [503, ['retry-after' => gmdate('D, d M Y H:i:s \G\M\T', time() + 300)], ['' => 300]],
            [503, ['retry-after' => '-5'], []],
            [503, ['retry-after' => '12abc'], []],
            [200, ['fixwire-rate-limits' => '86401:span;log, 30:error;nonsense, 40:nonsense, x:feedback, 50'], ['span' => 86_400, 'log' => 86_400, 'error' => 30, '' => 50]],
            [429, ['fixwire-rate-limits' => '120:span', 'retry-after' => '600'], ['span' => 120]],
            [503, ['fixwire-rate-limits' => '120:span', 'retry-after' => '600'], ['span' => 120, '' => 600]],
        ] as $i => [$status, $headers, $expected]) {
            Client::resetRateLimits();
            $ingest = new FakeIngest();
            $ingest->answer = static fn(): array => [$status, $headers];
            $hub = $ingest->hub();
            $hub->captureMessage('first');
            $now = microtime(true);
            $hub->flush();
            $paused = (new \ReflectionProperty(Client::class, 'paused'))->getValue();
            self::assertIsArray($paused);
            self::assertSame(array_keys($expected), array_keys($paused), "case {$i}");
            foreach ($expected as $category => $seconds) {
                self::assertEqualsWithDelta($now + $seconds, $paused[$category], 2.5, "case {$i}: {$category}");
            }
        }

        Client::resetRateLimits();
        $ingest = new FakeIngest();
        $ingest->answer = static fn(int $n): array => [503, ['retry-after' => '30']];
        $hub = $ingest->hub();
        $hub->captureMessage('first');
        self::assertFalse($hub->flush());
        self::assertCount(1, $ingest->requests(), 'not retried before Retry-After');
        $hub->captureMessage('second');
        self::assertFalse($hub->flush());
        self::assertCount(1, $ingest->requests(), 'paused');
    }

    public function testStopsAFlushWhenFixwireDoesNotAnswer(): void
    {
        $ingest = new FakeIngest();
        $ingest->answer = static fn(): array => [0, ['error' => 'Connection timed out']];
        $hub = $ingest->hub(['traces_sample_rate' => 1.0]);
        $hub->captureMessage('one');
        $hub->startSpan('job')->finish();
        $hub->captureFeedback(new Feedback('slow'));
        self::assertFalse($hub->flush());
        self::assertCount(2, $ingest->requests(), 'the logs, tried twice; not the spans nor the feedback');
        $hub->captureMessage('two');
        self::assertFalse($hub->flush());
        self::assertCount(4, $ingest->requests(), 'the next flush tries again');
    }

    public function testSplitsBatchesByTheirSize(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['redact' => false, 'max_queue' => 200, 'error_budget' => ['enabled' => false]]);
        for ($i = 0; $i < 150; $i++) {
            $hub->captureMessage("small {$i}");
        }
        // Records of about 0.9 MB each: 90 strings of 1 kB in each of 10 contexts.
        $context = array_fill_keys(array_map(static fn(int $k): string => "k{$k}", range(1, 90)), str_repeat('x', 1000));
        for ($i = 0; $i < 6; $i++) {
            $hub->withScope(static function (Scope $scope) use ($hub, $context, $i): void {
                foreach (range(1, 10) as $c) {
                    $scope->setContext("c{$c}", $context);
                }
                $hub->captureMessage("large {$i}");
            });
        }
        self::assertTrue($hub->flush());
        $requests = $ingest->requests('/v1/logs');
        self::assertSame([100, 55, 1], array_map(static fn(array $r): int => \count(FakeIngest::logRecords([$r])), $requests));
        foreach ($requests as $r) {
            $sent = \strlen((string) json_encode($r['body'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION));
            self::assertLessThanOrEqual(5 * 1024 * 1024, $sent, 'within the protocol\'s 5 MB, the export included');
        }
    }

    public function testShedsBreadcrumbsThenContextsFromRecordsOverOneMegabyte(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['redact' => false, 'max_breadcrumbs' => 2000, 'error_budget' => ['enabled' => false]]);
        $kB = str_repeat('x', 1000);
        $big = array_fill_keys(array_map(static fn(int $k): string => "k{$k}", range(1, 100)), $kB);
        $hub->captureMessage('small');
        for ($i = 0; $i < 1200; $i++) {
            $hub->addBreadcrumb(new Breadcrumb('n', $kB));
        }
        $hub->getScope()->setContext('small', ['a' => 1]);
        $hub->captureMessage('breadcrumbs shed');
        foreach (range(1, 11) as $c) {
            $hub->getScope()->setContext("c{$c}", $big);
        }
        $hub->captureMessage('contexts shed too');
        foreach (range(1, 11) as $c) {
            $hub->getScope()->setExtra("e{$c}", $big);
        }
        self::assertNull($hub->captureMessage('dropped'));
        $hub->flush();
        $kept = [];
        foreach (FakeIngest::logRecords($ingest->requests()) as $rec) {
            $a = FakeIngest::kv($rec['attributes']);
            $kept[FakeIngest::anyValue($rec['body'])] = [isset($a['fixwire.breadcrumbs']), isset($a['fixwire.contexts'])];
            self::assertLessThanOrEqual(1024 * 1024, \strlen((string) json_encode($rec, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION)));
        }
        self::assertSame(['small' => [false, false], 'breadcrumbs shed' => [false, true], 'contexts shed too' => [false, false]], $kept);
    }

    public function testCapsTheQueueForFeedbackToo(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['max_queue' => 2]);
        self::assertNotNull($hub->captureFeedback(new Feedback('one')));
        self::assertNotNull($hub->captureFeedback(new Feedback('two')));
        self::assertNull($hub->captureFeedback(new Feedback('three')));
    }

    public function testKeepsValuesWithinTheirLimits(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub();
        $endless = (static function (): \Generator {
            for ($i = 0; ; $i++) {
                yield $i;
            }
        })();
        $deepMap = 'leaf';
        $deepList = 'leaf';
        for ($i = 0; $i < 12; $i++) {
            $deepMap = ['a' => $deepMap];
            $deepList = [$deepList];
        }
        $loop = new \stdClass();
        $loop->name = 'loop';
        $loop->self = $loop;
        $broken = new class implements \JsonSerializable, \Stringable {
            public function jsonSerialize(): mixed
            {
                throw new \LogicException('not now');
            }

            public function __toString(): string
            {
                throw new \LogicException('not now');
            }
        };
        $unprintable = new class implements \Stringable {
            public function __toString(): string
            {
                throw new \LogicException('not now');
            }
        };
        $wide = array_fill(0, 100, array_fill(0, 100, array_fill(0, 100, 1)));
        $scope = $hub->getScope();
        $scope->setExtra('rows', $endless);
        $scope->setExtra('deep_map', $deepMap);
        $scope->setExtra('deep_list', $deepList);
        $scope->setExtra('loop', $loop);
        $scope->setExtra('broken', $broken);
        $scope->setExtra('unprintable', $unprintable);
        $scope->setExtra('numbers', [\NAN, \INF, -\INF, 1.5]);
        $scope->setExtra('long', range(1, 150));
        $scope->setExtra('wide', $wide);
        self::assertNotNull($hub->captureMessage('with odd values'));
        $hub->flush();
        $a = FakeIngest::kv(FakeIngest::logRecords($ingest->requests())[0]['attributes']);
        self::assertSame(range(0, 99), $a['rows'], 'an iterator is read up to 100 items');
        $map = $a['deep_map'];
        $list = $a['deep_list'];
        for ($level = 1; $level < 10; $level++) {
            $map = $map['a'];
            $list = $list[0];
        }
        self::assertSame(['a' => '[Object]'], $map, 'ten levels, then a marker');
        self::assertSame(['[Array]'], $list);
        self::assertSame(['name' => 'loop', 'self' => '[Circular ~]'], $a['loop']);
        self::assertSame('[Unreadable]', $a['broken']);
        self::assertSame('[Unreadable]', $a['unprintable']);
        self::assertSame(['NaN', 'Infinity', '-Infinity', 1.5], $a['numbers']);
        self::assertSame(range(1, 100), $a['long']);
        $walked = 0;
        array_walk_recursive($a['wide'], static function () use (&$walked): void {
            $walked++;
        });
        self::assertLessThanOrEqual(10_000, $walked, 'at most 10,000 items walked in a value');
        self::assertGreaterThan(9_000, $walked);
    }

    public function testCutsStringsOnCharacterBoundaries(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['traces_sample_rate' => 1.0]);
        $fits = str_repeat('a', 1022) . 'é'; // 1,024 bytes
        $over = str_repeat('a', 1020) . '€bb'; // 1,025 bytes, the cut inside "€"
        $hub->getScope()->setExtra('fits', $fits);
        $hub->getScope()->setExtra('over', $over);
        $hub->getScope()->setExtra(str_repeat('k', 2000), 'long key');
        $hub->getScope()->setExtra('exception.stacktrace', str_repeat("at frame\n", 8000));
        $hub->getScope()->setTag('tag', str_repeat('é', 600));
        $hub->captureMessage(str_repeat('m', 5000));
        $span = $hub->startSpan(str_repeat('s', 2000), 'task', ['over' => $over, 'exception.stacktrace' => str_repeat('x', 70_000)]);
        $span->setError(str_repeat('e', 2000));
        $span->finish();
        $hub->flush();

        $rec = FakeIngest::logRecords($ingest->requests('/v1/logs'))[0];
        $a = FakeIngest::kv($rec['attributes']);
        self::assertSame($fits, $a['fits']);
        self::assertSame(str_repeat('a', 1020) . '...', $a['over']);
        self::assertSame(str_repeat('m', 1021) . '...', FakeIngest::anyValue($rec['body']));
        self::assertSame('long key', $a[str_repeat('k', 1021) . '...']);
        self::assertSame(65_536, \strlen($a['exception.stacktrace']));
        self::assertStringEndsWith('...', $a['exception.stacktrace']);
        self::assertSame(str_repeat('é', 510) . '...', $a['fixwire.tags']['tag']);
        $s = FakeIngest::spans($ingest->requests('/v1/traces'))[0];
        $sa = FakeIngest::kv($s['attributes']);
        self::assertSame(str_repeat('s', 1021) . '...', $s['name']);
        self::assertSame(str_repeat('e', 1021) . '...', $s['status']['message']);
        self::assertSame(str_repeat('a', 1020) . '...', $sa['over']);
        self::assertSame(65_536, \strlen($sa['exception.stacktrace']));

        $short = new FakeIngest();
        $short->hub(['max_value_length' => 10])->captureMessage('ünïcödé text');
        Hub::current()->flush();
        self::assertSame('ünïc...', FakeIngest::anyValue(FakeIngest::logRecords($short->requests())[0]['body']));
    }

    public function testRedactsBeforeTheCut(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub();
        $key = '-----BEGIN ' . 'RSA PRIVATE KEY-----' . "\n" . str_repeat(str_repeat('A', 64) . "\n", 20) . '-----END ' . 'RSA PRIVATE KEY-----';
        $jwt = 'eyJ' . 'hbGciOiJIUzI1NiJ9.eyJ' . 'zdWIiOiIxMjM0NTY3ODkwIn0.' . str_repeat('s', 40);
        // Both start before the cut and end after it.
        $hub->getScope()->setExtra('pem', str_repeat('p', 990) . ' ' . $key . ' tail');
        $hub->getScope()->setExtra('jwt', str_repeat('j', 1000) . ' ' . $jwt);
        $hub->captureMessage(str_repeat('m', 990) . ' ' . $key);
        $hub->flush();
        $rec = FakeIngest::logRecords($ingest->requests())[0];
        $a = FakeIngest::kv($rec['attributes']);
        self::assertSame(str_repeat('p', 990) . ' [REDACTED:private_key] tail', $a['pem']);
        self::assertSame(str_repeat('j', 1000) . ' [REDACTED:jwt]', $a['jwt']);
        self::assertSame(str_repeat('m', 990) . ' [REDACTED:private_key]', FakeIngest::anyValue($rec['body']));
    }

    public function testKeepsTheNewestFramesAndTenExceptions(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub();
        $deep = static function (int $n) use (&$deep): never {
            if ($n === 0) {
                throw new \RuntimeException('deep down');
            }
            $deep($n - 1);
        };
        try {
            $deep(150);
        } catch (\RuntimeException $e) {
            $hub->captureException($e);
        }
        $previous = null;
        for ($i = 0; $i < 11; $i++) {
            $previous = new \RuntimeException("link {$i}", 0, $previous);
        }
        $hub->captureException($previous);
        $a = new \RuntimeException('a');
        $b = new \RuntimeException('b', 0, $a);
        (new \ReflectionProperty(\Exception::class, 'previous'))->setValue($a, $b);
        $hub->captureException($b);
        $few = new FakeIngest();
        $few->hub(['max_stack_frames' => 20])->captureException($e);
        Hub::current()->flush();
        $hub->flush();

        [$deepRec, $chainRec, $loopRec] = FakeIngest::logRecords($ingest->requests());
        $frames = FakeIngest::kv($deepRec['attributes'])['fixwire.exceptions'][0]['frames'];
        self::assertCount(100, $frames);
        self::assertSame('{closure}', $frames[99]['function'], 'the newest, where it threw');
        self::assertNotSame('{main}', $frames[0]['function'], 'the oldest are left out');
        $chain = FakeIngest::kv($chainRec['attributes'])['fixwire.exceptions'];
        self::assertSame(['link 10', 'link 9', 'link 8', 'link 7', 'link 6', 'link 5', 'link 4', 'link 3', 'link 2', 'link 1'], array_column($chain, 'message'));
        self::assertSame(['b', 'a'], array_column(FakeIngest::kv($loopRec['attributes'])['fixwire.exceptions'], 'message'));
        $fewFrames = FakeIngest::kv(FakeIngest::logRecords($few->requests())[0]['attributes'])['fixwire.exceptions'][0]['frames'];
        self::assertCount(20, $fewFrames);
        self::assertSame($frames[99], $fewFrames[19]);
    }

    public function testPassesOnOnlyWellFormedTraceHeaders(): void
    {
        $hub = (new FakeIngest())->hub();
        $traceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $at = static function (?string $tracestate, ?string $baggage) use ($hub, $traceparent): array {
            $span = $hub->continueTrace($traceparent, $tracestate, $baggage, 'GET /');
            $span->finish();

            return [$span->tracestate, $span->baggage];
        };
        $state512 = 'fw=' . str_repeat('a', 509);
        $baggage8192 = 'k=' . str_repeat('v', 8190);
        self::assertSame([$state512, $baggage8192], $at($state512, $baggage8192));
        self::assertSame([null, null], $at($state512 . 'a', $baggage8192 . 'v'), 'dropped whole, not cut');
        self::assertSame([null, null], $at("fw=1\r\nX-Injected: 1", "user=1\nX: 2"));
        self::assertSame([null, null], $at("fw=1\x00", "user=1\x7F"));
        self::assertSame(["fw=1,\tother=2", "user=1,\tid=2"], $at("fw=1,\tother=2", "user=1,\tid=2"), 'tabs are spaces');

        foreach ([
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01' => true,
            ' 00-4BF92F3577B34DA6A3CE929D0E0E4736-00F067AA0BA902B7-00 ' => true,
            '01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01' => false,
            'ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01' => false,
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-extra' => false,
            '00-00000000000000000000000000000000-00f067aa0ba902b7-01' => false,
            '00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01' => false,
            '00-4bf92f3577b34da6a3ce929d0e0e473g-00f067aa0ba902b7-01' => false,
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-1' => false,
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-0x' => false,
        ] as $header => $ok) {
            self::assertSame($ok, Span::parseTraceparent($header) !== null, $header);
        }
    }

    public function testSendsTraceHeadersOnlyToTheirTargets(): void
    {
        $matches = static function (array $targets, string $url): bool {
            $client = new Client(Options::fromArray(['dsn' => FakeIngest::DSN, 'trace_propagation_targets' => $targets, 'transport' => new FakeIngest()]));

            return $client->shouldPropagate($url);
        };
        foreach ([
            [['example.com'], 'https://example.com', true],
            [['example.com'], 'https://api.example.com/x?y=1', true],
            [['example.com'], 'https://API.Example.COM/x', true],
            [['Example.com'], 'http://deep.api.example.com:8080/', true],
            [['example.com'], 'https://badexample.com/', false],
            [['example.com'], 'https://example.com.evil.net/', false],
            [['example.com'], 'https://evil.net/example.com', false],
            [['example.com'], 'https://evil.net/?next=example.com', false],
            [['example.com'], 'https://evil.net/#example.com', false],
            [['example.com'], 'https://example.com@evil.net/', false],
            [['example.com:8443'], 'https://api.example.com:8443/', true],
            [['example.com:8443'], 'https://example.com/', false],
            [['example.com:443'], 'https://example.com/x', true],
            [['[::1]:8080'], 'http://[::1]:8080/x', true],
            [['https://api.example.com/v2'], 'https://api.example.com/v2/items?page=2', true],
            [['https://api.example.com/v2'], 'https://API.example.com/v2/items', true],
            [['https://api.example.com/v2'], 'https://user:secret@api.example.com/v2/items', true],
            [['https://api.example.com/v2'], 'https://api.example.com/v1/items', false],
            [['https://api.example.com/v2'], 'http://api.example.com/v2', false],
            [['https://api.example.com/v2'], 'https://evil.net/?https://api.example.com/v2', false],
            [['/api'], 'https://example.com/api/items', false],
            [['example.com'], '/api/items', false],
            [[], 'https://example.com/', false],
        ] as $i => [$targets, $url, $expected]) {
            self::assertSame($expected, $matches($targets, $url), "case {$i}: {$url}");
        }
    }

    public function testCapsSpanAttributesAndRedactsTheirStatus(): void
    {
        $ingest = new FakeIngest();
        $hub = $ingest->hub(['traces_sample_rate' => 1.0]);
        $attributes = [];
        for ($i = 0; $i < 200; $i++) {
            $attributes["a{$i}"] = $i;
        }
        $span = $hub->startSpan('import', 'task', $attributes);
        $span->setAttribute('late', 1);
        $span->setAttribute('a5', 'changed');
        $span->setError('import for ada@example.com failed');
        $span->finish();
        $hub->flush();
        $s = FakeIngest::spans($ingest->requests())[0];
        $a = FakeIngest::kv($s['attributes']);
        self::assertCount(128, $a);
        self::assertSame('task', $a['fixwire.op']);
        self::assertSame('changed', $a['a5']);
        self::assertArrayNotHasKey('late', $a);
        self::assertSame('import for [REDACTED:email] failed', $s['status']['message']);
    }

    public function testCountsAtMost5000UsersApart(): void
    {
        $sessions = new Sessions();
        $now = 1_791_300_000.0;
        for ($i = 0; $i < 5100; $i++) {
            $sessions->record('exited', "user{$i}", $now);
        }
        $sessions->record('crashed', 'user7', $now);
        $sessions->record('exited', 'user7', $now + 60);
        $o = Options::fromArray(['release' => 'shop@1.0.0']);
        $bodies = $sessions->take($o);
        self::assertCount(2, $bodies, 'at most 5,000 aggregates a request');
        $aggregates = array_merge($bodies[0]['aggregates'], $bodies[1]['aggregates']);
        self::assertCount(5000, $bodies[0]['aggregates']);
        $apart = array_filter($aggregates, static fn(array $a): bool => isset($a['did']));
        self::assertCount(5001, $apart, 'user7 again, the next minute');
        $together = array_values(array_filter($aggregates, static fn(array $a): bool => !isset($a['did'])));
        self::assertSame(100, $together[0]['exited'], 'the users past 5,000, counted without them');
        self::assertSame([], $sessions->take($o));
        $sessions->record('exited', 'user6000', $now);
        self::assertSame('user6000', $sessions->take($o)[0]['aggregates'][0]['did'], 'counted apart again after a send');
    }

    public function testLeavesTheParentsQueueToTheParentAfterAFork(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('needs pcntl and posix');
        }
        $file = (string) tempnam(sys_get_temp_dir(), 'fixwire-fork');
        try {
            $hub = (new FakeIngest($file))->hub();
            $hub->captureMessage('from the parent');
            $pid = pcntl_fork();
            if ($pid === 0) {
                $hub->captureMessage('from the child');
                $hub->flush();
                posix_kill((int) getmypid(), \SIGKILL); // no PHPUnit shutdown in the child
            }
            self::assertGreaterThan(0, $pid);
            pcntl_waitpid($pid, $status);
            $hub->flush();
            $bodies = [];
            foreach (array_filter(explode("\n", (string) file_get_contents($file))) as $line) {
                $bodies[] = array_map(
                    static fn(array $rec): mixed => FakeIngest::anyValue($rec['body']),
                    FakeIngest::logRecords([json_decode($line, true, 512, \JSON_THROW_ON_ERROR)]),
                );
            }
            self::assertSame([['from the child'], ['from the parent']], $bodies);
        } finally {
            @unlink($file);
        }
    }

    public function testFlushesWithinItsTimeout(): void
    {
        $ingest = new FakeIngest();
        $ingest->answer = static function (): array {
            usleep(300_000);

            return [200, []];
        };
        $hub = $ingest->hub(['traces_sample_rate' => 1.0]);
        $hub->captureMessage('one');
        $hub->startSpan('job')->finish();
        $hub->captureFeedback(new Feedback('slow'));
        $start = microtime(true);
        self::assertFalse($hub->flush(0.5));
        self::assertLessThan(0.9, microtime(true) - $start);
        self::assertCount(2, $ingest->requests(), 'the third was out of time');

        // No retry that the deadline would cut short.
        $ingest = new FakeIngest();
        $ingest->answer = static fn(): array => [500, []];
        $hub = $ingest->hub(['timeout' => 0.5]);
        $hub->captureMessage('one');
        $start = microtime(true);
        self::assertFalse($hub->flush());
        self::assertLessThan(0.5, microtime(true) - $start);
        self::assertCount(1, $ingest->requests());
    }
}
