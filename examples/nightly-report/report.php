<?php

// A cron job reporting to Fixwire: check-ins to a monitor, one scope per
// account, carrying on after a failure, a summary warning and a trace for the
// run. What was captured is sent as the script ends.
//
//   FIXWIRE_DSN=https://<key>@<host> php nightly-report/report.php

declare(strict_types=1);

use Fixwire\CheckIn;
use Fixwire\CheckInStatus;
use Fixwire\Level;
use Fixwire\MonitorConfig;
use Fixwire\Scope;

require \dirname(__DIR__) . '/vendor/autoload.php';

final class NoInvoices extends RuntimeException {}

// The DSN comes from FIXWIRE_DSN; without it, Fixwire does nothing.
Fixwire\init(['release' => getenv('RELEASE') ?: 'nightly-report@1.0.0', 'traces_sample_rate' => 1.0]);

// The first check-in creates the monitor: every night at 3, Berlin time, with 10 minutes'
// margin and 30 minutes at most. Fixwire then also notices a night the job does not run.
$monitor = 'nightly-report';
$schedule = MonitorConfig::crontab('0 3 * * *', checkInMargin: 10, maxRuntime: 30, timezone: 'Europe/Berlin');
$run = Fixwire\captureCheckIn(new CheckIn($monitor, CheckInStatus::InProgress, config: $schedule));
$started = microtime(true);

// Invoices per account, in cents; globex has none, which the report can't handle.
$accounts = ['acme' => [1200, 800], 'globex' => [], 'initech' => [4300]];

$failed = Fixwire\trace(static function () use ($accounts): int {
    $failed = 0;
    foreach ($accounts as $account => $invoices) {
        // A scope per account: its tag stays on its own events.
        Fixwire\withScope(static function (Scope $scope) use ($account, $invoices, &$failed): void {
            $scope->setTag('account', $account);

            try {
                echo Fixwire\trace(static fn() => buildReport($account, $invoices), "report {$account}", 'task'), "\n";
            } catch (RuntimeException $e) {
                $failed++;
                Fixwire\captureException($e); // and carry on with the next account
            }
        });
    }

    return $failed;
}, 'nightly-report', 'task');

if ($failed > 0) {
    Fixwire\captureMessage("{$failed} of " . \count($accounts) . ' reports failed', Level::Warning);
}
$status = $failed > 0 ? CheckInStatus::Error : CheckInStatus::Ok;
Fixwire\captureCheckIn(new CheckIn($monitor, $status, $run, microtime(true) - $started));

exit($failed > 0 ? 1 : 0);

/** @param list<int> $invoices */
function buildReport(string $account, array $invoices): string
{
    try {
        $total = total($invoices);
    } catch (NoInvoices $e) {
        throw new RuntimeException("building the report for {$account}", 0, $e);
    }

    return \sprintf('%s: %d invoices, %.2f EUR', $account, \count($invoices), $total / 100);
}

/** @param list<int> $invoices */
function total(array $invoices): int
{
    if ($invoices === []) {
        throw new NoInvoices('no invoices');
    }

    return array_sum($invoices);
}
