# Nightly report (a cron job)

```sh
composer install    # in examples/
FIXWIRE_DSN=https://<key>@<host> php nightly-report/report.php
```

The job builds a report per account. One account (`globex`) has no
invoices: the job reports that failure with the account as a tag, carries
on with the others, sends a summary warning and exits 1.

What arrives in Fixwire:

- **Check-ins** for the `nightly-report` monitor: `in_progress` when the
  run starts and `error` when it ends, with its duration. The first
  check-in creates the monitor (every night at 3, Berlin time, 10 minutes'
  margin, 30 minutes at most), so Fixwire also notices a night the job does
  not run at all.
- **The failure**, tagged `account: globex`, with the chain `building the
  report for globex` caused by `NoInvoices: no invoices`.
- **The summary warning**, without the account's tag: each account had its
  own scope (`Fixwire\withScope()`).
- **A trace for the run**, with a span per account; the failure is linked
  to it.

How it is wired, in `report.php`:

```php
Fixwire\init(['release' => 'nightly-report@1.0.0', 'traces_sample_rate' => 1.0]);

$schedule = Fixwire\MonitorConfig::crontab('0 3 * * *', checkInMargin: 10, maxRuntime: 30, timezone: 'Europe/Berlin');
$run = Fixwire\captureCheckIn(new Fixwire\CheckIn('nightly-report', Fixwire\CheckInStatus::InProgress, config: $schedule));
// … the reports …
Fixwire\captureCheckIn(new Fixwire\CheckIn('nightly-report', $status, $run, microtime(true) - $started));
exit($failed > 0 ? 1 : 0); // what was captured is sent as the script ends
```

The check-ins are sent at once; the rest when the script ends, an
uncaught exception or a fatal error included. For a job that fails by
throwing, `Fixwire\withMonitor('nightly-report', $schedule, $job)` sends both
check-ins around it.
