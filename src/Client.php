<?php

declare(strict_types=1);

namespace Fixwire;

use Fixwire\Internal\Redaction\Redactor;
use Fixwire\Transport\HttpTransport;
use Fixwire\Transport\Transport;

/**
 * Sends to one project. What it captures waits in memory and is sent in one go when it flushes:
 * at the end of the request (or the script), or when a worker calls \Fixwire\flush() after a job.
 * Check-ins are sent at once, so a monitor knows a run started.
 */
final class Client
{
    public const SDK_NAME = 'fixwire.php';
    public const SDK_VERSION = '0.1.0';

    // The kinds of data, as the protocol's rate limits name them.
    private const ERROR = 'error';
    private const SPAN = 'span';
    private const SESSION = 'session';
    private const CHECK_IN = 'check_in';
    private const FEEDBACK = 'feedback';

    /** The log records and spans per request to Fixwire. */
    private const BATCH = 100;

    private readonly Options $options;

    private readonly ?Dsn $dsn;

    private readonly Transport $transport;

    private readonly ?Redactor $redactor;

    private readonly Budget $budget;

    private readonly ?Sessions $sessions;

    /** @var list<array<string, mixed>> */
    private array $records = [];

    /** @var list<array<string, mixed>> */
    private array $spans = [];

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> path, category, body */
    private array $requests = [];

    /** @var \WeakMap<\Throwable, bool> */
    private \WeakMap $captured;

    /** @var array<string, float> category ("" for all) → paused until, within this process */
    private static array $paused = [];

    /**
     * @throws \InvalidArgumentException for a malformed DSN
     */
    public function __construct(Options $options)
    {
        $options->applyDefaults();
        $this->options = $options;
        $this->budget = new Budget($options->errorBudget);
        $this->redactor = $options->redact ? Redactor::create($options->sensitiveKeys) : null;
        $this->transport = $options->transport ?? new HttpTransport($options->timeout);
        $this->dsn = Options::empty($options->dsn) ? null : Dsn::parse((string) $options->dsn);
        $this->sessions = $this->dsn !== null && $options->sessionsOn() ? new Sessions() : null;
        $this->captured = new \WeakMap();
    }

    /** Whether the client sends: false without a DSN. */
    public function isEnabled(): bool
    {
        return $this->dsn !== null;
    }

    /** The options, defaults filled in. */
    public function options(): Options
    {
        return $this->options;
    }

    /** @internal */
    public function sessions(): ?Sessions
    {
        return $this->sessions;
    }

    /** @internal */
    public function budget(): Budget
    {
        return $this->budget;
    }

    /** Whether trace headers may go to a URL: it holds one of the trace propagation targets. */
    public function shouldPropagate(string $url): bool
    {
        foreach ($this->options->tracePropagationTargets as $target) {
            if ($target !== '' && str_contains($url, $target)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a throwable was captured already, so that a log record of it is not sent twice. */
    public function isCaptured(\Throwable $throwable): bool
    {
        return isset($this->captured[$throwable]);
    }

    /** @internal sends an event with what the scope knows: its id, or null when not sent (never throws) */
    public function capture(Event $e, Scope $scope, ?Span $span): ?string
    {
        try {
            return $this->doCapture($e, $scope, $span);
        } catch (\Throwable $ex) {
            $this->log('capturing an event failed: ' . $ex->getMessage());

            return null;
        }
    }

    private function doCapture(Event $e, Scope $scope, ?Span $span): ?string
    {
        if ($this->dsn === null) {
            return null;
        }
        $scope->applyTo($e, $span);
        // The session counts the error whether or not it is sent.
        if ($e->exceptions !== []) {
            $scope->session?->mark(!$e->exceptions[0]->handled);
        } elseif ($e->level === Level::Error || $e->level === Level::Fatal) {
            $scope->session?->mark(false);
        }
        if ($e->throwable !== null) {
            $this->captured[$e->throwable] = true;
        }
        $held = $this->budget->allow(Budget::issueOf($e), microtime(true));
        if ($held < 0) {
            $this->log('dropped an event: over the error budget');

            return null;
        }
        if ($this->options->sampleRate < 1 && mt_rand() / mt_getrandmax() >= $this->options->sampleRate) {
            return null;
        }
        $e->suppressed = $held;
        $e->eventId ??= Ids::new(16);
        $e->timestamp ??= microtime(true);
        $e->level ??= $e->exceptions === [] ? Level::Info : Level::Error;
        if (!$this->options->sendDefaultPii) {
            if ($e->user !== null) {
                $e->user->ipAddress = null;
            }
            if ($e->request !== null) {
                $e->request = clone $e->request; // the scope's stays whole
                $e->request->headers = array_filter(
                    $e->request->headers,
                    static fn(string|int $name): bool => !Request::isSensitiveHeader((string) $name),
                    \ARRAY_FILTER_USE_KEY,
                );
            }
        } elseif ($e->request?->clientAddress !== null) {
            $e->user ??= new User();
            $e->user->ipAddress ??= $e->request->clientAddress;
        }
        if ($this->options->beforeSend !== null) {
            try {
                $changed = ($this->options->beforeSend)($e);
                if ($changed === null) {
                    return null;
                }
                $e = $changed;
            } catch (\Throwable $ex) {
                $this->log('before_send failed, sending the event as it is: ' . $ex->getMessage());
            }
        }
        if (!$this->room()) {
            return null;
        }
        $this->records[] = Otlp::eventRecord($e, $this->redactor);

        return $e->eventId;
    }

    /**
     * @internal finished spans, sent at the next flush
     *
     * @param list<Span> $spans
     */
    public function queueSpans(array $spans): void
    {
        foreach ($spans as $span) {
            if (!$this->room()) {
                return;
            }
            try {
                $this->spans[] = Otlp::span($span->record(), $this->redactor);
            } catch (\Throwable $ex) {
                $this->log('recording a span failed: ' . $ex->getMessage());
            }
        }
    }

    /**
     * Reports a run of a scheduled job, at once: in progress when it starts, then ok or error with
     * the returned id. Its id, or null when it was not sent. Never throws.
     */
    public function captureCheckIn(CheckIn $checkIn): ?string
    {
        try {
            return $this->doCaptureCheckIn($checkIn);
        } catch (\Throwable $ex) {
            $this->log('sending a check-in failed: ' . $ex->getMessage());

            return null;
        }
    }

    private function doCaptureCheckIn(CheckIn $checkIn): ?string
    {
        if ($this->dsn === null || trim($checkIn->monitor) === '') {
            return null;
        }
        $id = $checkIn->id ?? Ids::new(16);
        $body = [
            'sdk' => self::sdk(),
            'check_in_id' => $id,
            'status' => $checkIn->status->value,
            'environment' => $this->options->environment,
        ];
        if ($checkIn->duration !== null && $checkIn->duration > 0) {
            $body['duration'] = $checkIn->duration;
        }
        if ($checkIn->config !== null) {
            $body['monitor_config'] = $checkIn->config->toWire();
        }

        return $this->post('/v1/check-ins/' . rawurlencode($checkIn->monitor), self::CHECK_IN, $body) ? $id : null;
    }

    /** @internal queues feedback: its id, or null when not sent (never throws) */
    public function captureFeedback(Feedback $f, Scope $scope, ?Span $span): ?string
    {
        try {
            return $this->doCaptureFeedback($f, $scope, $span);
        } catch (\Throwable $ex) {
            $this->log('capturing feedback failed: ' . $ex->getMessage());

            return null;
        }
    }

    /** @internal */
    private function doCaptureFeedback(Feedback $f, Scope $scope, ?Span $span): ?string
    {
        $message = trim((string) $f->message);
        $score = is_finite($f->score) ? max(-1.0, min(1.0, $f->score)) : 0.0;
        if ($this->dsn === null || ($message === '' && $score == 0)) {
            return null;
        }
        $user = $scope->getUser();
        $body = array_filter([
            'message' => $message,
            'name' => $f->name ?? $user?->username,
            'email' => $f->email ?? $user?->email,
            'url' => $f->url,
        ], static fn($v) => $v !== null && $v !== '');
        if ($score != 0) {
            $body['score'] = $score;
        }
        $body = Otlp::scrub($body, $this->redactor);
        $id = Ids::new(16);
        $body += array_filter([
            'sdk' => self::sdk(),
            'feedback_id' => $id,
            'timestamp' => microtime(true),
            'source' => $f->source ?? 'api',
            'environment' => $this->options->environment,
            'trace_id' => $f->traceId ?? $span?->traceId,
            'event_id' => $f->eventId,
            'release' => $this->options->release,
        ], static fn($v) => $v !== null);
        $this->requests[] = ['/v1/feedback', self::FEEDBACK, $body];

        return $id;
    }

    /**
     * Sends what was captured; false when something could not be sent.
     */
    public function flush(): bool
    {
        if ($this->dsn === null) {
            return true;
        }
        $ok = true;
        $session = $this->sessions?->take($this->options);
        if ($session !== null) {
            $this->requests[] = ['/v1/sessions', self::SESSION, $session];
        }
        $records = $this->records;
        $spans = $this->spans;
        $requests = $this->requests;
        $this->records = $this->spans = $this->requests = [];
        foreach (array_chunk($records, self::BATCH) as $batch) {
            $ok = $this->send('/v1/logs', self::ERROR, fn(): array => Otlp::logs($this->options, $batch)) && $ok;
        }
        foreach (array_chunk($spans, self::BATCH) as $batch) {
            $ok = $this->send('/v1/traces', self::SPAN, fn(): array => Otlp::traces($this->options, $batch)) && $ok;
        }
        foreach ($requests as [$path, $category, $body]) {
            $ok = $this->send($path, $category, static fn(): array => $body) && $ok;
        }

        return $ok;
    }

    /**
     * One request, never throwing: a failure is logged (with the debug option) and the data dropped.
     *
     * @param \Closure(): array<string, mixed> $body
     */
    private function send(string $path, string $category, \Closure $body): bool
    {
        try {
            return $this->post($path, $category, $body());
        } catch (\Throwable $ex) {
            $this->log("sending to {$path} failed: " . $ex->getMessage());

            return false;
        }
    }

    private function room(): bool
    {
        if (\count($this->records) + \count($this->spans) + \count($this->requests) >= $this->options->maxQueue) {
            $this->log('dropping: the queue is full');

            return false;
        }

        return true;
    }

    /**
     * Sends one request now, honouring rate limits; one retry when there was no answer or a 5xx.
     *
     * @param array<string, mixed> $body
     */
    private function post(string $path, string $category, array $body): bool
    {
        if ($this->dsn === null || $this->paused($category)) {
            $this->log("dropping a {$category} request: paused");

            return false;
        }
        $json = json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            $this->log("dropping a {$category} request: " . json_last_error_msg());

            return false;
        }
        $headers = [
            'Authorization' => 'Bearer ' . $this->dsn->key,
            'Content-Type' => 'application/json',
            'User-Agent' => self::SDK_NAME . '/' . self::SDK_VERSION,
        ];
        if (\function_exists('gzencode')) {
            $json = (string) gzencode($json, 1);
            $headers['Content-Encoding'] = 'gzip';
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            [$status, $answer] = $this->transport->send($this->dsn->url($path), $json, $headers);
            $this->limit($answer['fixwire-rate-limits'] ?? null, $status, $answer['retry-after'] ?? null);
            if ($status >= 200 && $status < 300) {
                return true;
            }
            if ($status !== 0 && $status < 500) {
                $this->log("{$category} request refused: {$status}");

                return false;
            }
        }
        $this->log("dropping a {$category} request ({$status}" . (isset($answer['error']) ? ': ' . $answer['error'] : '') . ')');

        return false;
    }

    private function paused(string $category): bool
    {
        $now = microtime(true);
        $stored = \function_exists('apcu_fetch') && \function_exists('apcu_enabled') && apcu_enabled() ? apcu_fetch('fixwire.rate_limits') : false;
        $paused = \is_array($stored) ? $stored + self::$paused : self::$paused;

        return ($paused[$category] ?? 0) > $now || ($paused[''] ?? 0) > $now;
    }

    /** Reads Fixwire-Rate-Limits ("<seconds>:<category;…>, …"; no categories means all) and a bare 429. */
    private function limit(?string $header, int $status, ?string $retryAfter): void
    {
        $now = microtime(true);
        $changed = false;
        if ($header === null && $status === 429) {
            $header = max((int) $retryAfter, 60) . ':';
        }
        foreach ($header === null ? [] : explode(',', $header) as $part) {
            $sc = explode(':', trim($part), 2);
            $secs = (int) $sc[0];
            if ($secs <= 0) {
                continue;
            }
            $cats = trim($sc[1] ?? '');
            foreach ($cats === '' ? [''] : explode(';', $cats) as $cat) {
                self::$paused[trim($cat)] = max(self::$paused[trim($cat)] ?? 0, $now + $secs);
                $changed = true;
            }
        }
        if ($changed && \function_exists('apcu_store') && \function_exists('apcu_enabled') && apcu_enabled()) {
            apcu_store('fixwire.rate_limits', self::$paused, 3600); // shared with the other requests of this server
        }
    }

    /** @internal forgets the rate limits (tests) */
    public static function resetRateLimits(): void
    {
        self::$paused = [];
    }

    /**
     * @internal
     *
     * @return array{name: string, version: string}
     */
    public static function sdk(): array
    {
        return ['name' => self::SDK_NAME, 'version' => self::SDK_VERSION];
    }

    /** @internal */
    public function log(string $message): void
    {
        if ($this->options->debug) {
            error_log('fixwire: ' . $message);
        }
    }
}
