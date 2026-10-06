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
    public const SDK_VERSION = '0.1.1';

    // The kinds of data, as the protocol's rate limits name them.
    private const ERROR = 'error';
    private const SPAN = 'span';
    private const SESSION = 'session';
    private const CHECK_IN = 'check_in';
    private const FEEDBACK = 'feedback';

    /** The categories a rate limit may name (fixwire-protocol §2); others are ignored. */
    private const CATEGORIES = ['error', 'log', 'span', 'session', 'check_in', 'feedback', 'file'];

    /** The log records and spans per request to Fixwire. */
    private const BATCH = 100;

    /** The bytes of a request of log records or spans, all of its JSON. */
    private const BATCH_BYTES = 5 * 1024 * 1024;

    /** The bytes of JSON an error or a message may take. */
    private const MAX_RECORD = 1024 * 1024;

    /** The longest pause an answer can ask for, in seconds: a day. */
    private const MAX_PAUSE = 86_400;

    /** The pause a 429 without Fixwire-Rate-Limits asks for at least, in seconds. */
    private const MIN_429_PAUSE = 60;

    /** The wait before the one retry of a request, in seconds (more tries would hold up the exit). */
    private const BACKOFF = 1.0;

    /** Where PHP-FPM workers share their pauses, followed by the project's hash. */
    private const SHARED_PAUSES = 'fixwire.rate_limits.';

    /** How bodies are encoded (and batches measured). */
    private const JSON = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_PARTIAL_OUTPUT_ON_ERROR;

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

    /** The pause after Fixwire didn't answer, shared by the PHP-FPM workers through APCu. */
    private readonly Unreachable $unreachable;

    /** The project's pauses are its own: a hash of the DSN, so apps sharing a server don't pause each other. */
    private readonly string $project;

    /** The process whose data is queued: a child of pcntl_fork() leaves it to its parent. */
    private int $pid;

    /** @var array<string, array<string, float>> project → category ("" for all) → paused until, within this process */
    private static array $paused = [];

    /**
     * Never throws: a malformed DSN, or an option that is broken, is said on PHP's error log (stderr
     * in the CLI), whether or not debug is on, and the client stays off.
     */
    public function __construct(Options $options)
    {
        $options->applyDefaults();
        $this->options = $options;
        $problems = $options->problems();
        try {
            $budget = new Budget($options->errorBudget);
            $redactor = $options->redact ? Redactor::create($options->sensitiveKeys) : null;
        } catch (\Throwable $e) {
            $problems[] = 'error_budget or sensitive_keys: ' . $e->getMessage();
            [$budget, $redactor] = [new Budget([]), null];
        }
        $this->budget = $budget;
        $this->redactor = $redactor;
        $this->transport = $options->transport ?? new HttpTransport($options->timeout);
        $dsn = null;
        try {
            $dsn = Options::empty($options->dsn) ? null : Dsn::parse((string) $options->dsn);
        } catch (\InvalidArgumentException) {
            $problems[] = 'the DSN must look like https://<key>@<host>';
        }
        if ($problems !== []) {
            error_log('fixwire: nothing is sent: ' . implode('; ', $problems));
            $dsn = null;
        }
        $this->dsn = $dsn;
        $this->unreachable = Unreachable::of($dsn === null ? '' : $dsn->baseUrl);
        $this->project = $dsn === null ? '' : substr(hash('sha256', $dsn->key . '@' . $dsn->baseUrl), 0, 16);
        $this->sessions = $this->dsn !== null && $options->sessionsOn() ? new Sessions() : null;
        $this->captured = new \WeakMap();
        $this->pid = (int) getmypid();
    }

    /** In a forked child, forgets what the parent queued: the parent sends it. */
    private function ownQueue(): void
    {
        $pid = (int) getmypid();
        if ($pid !== $this->pid) {
            $this->pid = $pid;
            $this->records = $this->spans = $this->requests = [];
            $this->sessions?->take($this->options);
        }
    }

    /** Whether the client sends: false without a DSN. */
    public function isEnabled(): bool
    {
        return $this->dsn !== null;
    }

    /** @internal whether something waits for the next flush */
    public function hasQueued(): bool
    {
        $this->ownQueue();

        return $this->dsn !== null && ($this->records !== [] || $this->spans !== [] || $this->requests !== [] || $this->sessions?->isEmpty() === false);
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

    /**
     * Whether trace headers may go to a URL: one of the trace propagation targets matches it,
     * compared without its user info, query and fragment (see Options::$tracePropagationTargets).
     */
    public function shouldPropagate(string $url): bool
    {
        $u = parse_url($url);
        if (!\is_array($u) || !isset($u['scheme'], $u['host']) || str_contains($u['host'], '\\')) {
            return false; // a relative URL, or one HTTP clients may read differently
        }
        $scheme = strtolower($u['scheme']);
        $host = strtolower($u['host']);
        $port = $u['port'] ?? ($scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null));
        $compared = $scheme . '://' . $host . (isset($u['port']) ? ':' . $u['port'] : '') . ($u['path'] ?? '');
        foreach ($this->options->tracePropagationTargets as $target) {
            if ($target === '' || $target[0] === '/') {
                continue; // a path on a browser page's origin: nothing here
            }
            if (str_contains($target, '://')) {
                // The scheme and host in any case, the path as it is.
                $slash = strpos($target, '/', strpos($target, '://') + 3);
                $prefix = $slash === false ? strtolower($target) : strtolower(substr($target, 0, $slash)) . substr($target, $slash);
                if (str_starts_with($compared, $prefix)) {
                    return true;
                }
                continue;
            }
            [$targetHost, $targetPort] = self::hostAndPort(strtolower($target));
            if (($host === $targetHost || str_ends_with($host, '.' . $targetHost)) && ($targetPort === null || $targetPort === $port)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A host target's host and port: example.com, example.com:8443, [::1]:8080.
     *
     * @return array{0: string, 1: ?int}
     */
    private static function hostAndPort(string $target): array
    {
        $colon = strrpos($target, ':');
        if ($colon === false || !ctype_digit(substr($target, $colon + 1))) {
            return [$target, null];
        }
        $host = substr($target, 0, $colon);
        if (str_contains($host, ':') && !str_ends_with($host, ']')) {
            return [$target, null]; // an IPv6 address without brackets: no port
        }

        return [$host, (int) substr($target, $colon + 1)];
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
        $record = self::fit(Otlp::eventRecord($e, $this->redactor, $this->options->maxValueLength));
        if ($record === null) {
            $this->log('dropped an event: over 1 MB without its breadcrumbs and contexts');

            return null;
        }
        $this->records[] = $record;

        return $e->eventId;
    }

    /**
     * An error or a message within MAX_RECORD bytes of JSON: without its breadcrumbs, then without
     * its contexts, if need be (PHP's frames carry no local variables); null when still over.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>|null
     */
    private static function fit(array $record): ?array
    {
        foreach ([null, 'fixwire.breadcrumbs', 'fixwire.contexts'] as $shed) {
            if ($shed !== null && \is_array($record['attributes'] ?? null)) {
                $record['attributes'] = array_values(array_filter(
                    $record['attributes'],
                    static fn(mixed $kv): bool => !\is_array($kv) || ($kv['key'] ?? null) !== $shed,
                ));
            }
            if (\strlen((string) json_encode($record, self::JSON)) <= self::MAX_RECORD) {
                return $record;
            }
        }

        return null;
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
                $this->spans[] = Otlp::span($span->record(), $this->redactor, $this->options->maxValueLength);
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
        // The app's own configuration (the slug too): cut, not redacted.
        $limit = $this->options->maxValueLength;
        $body = Otlp::cut($body, $limit);

        $deadline = microtime(true) + $this->options->timeout;

        return $this->post('/v1/check-ins/' . rawurlencode(Otlp::cutString($checkIn->monitor, $limit)), self::CHECK_IN, $body, $deadline) ? $id : null;
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
        if ($this->dsn === null || ($message === '' && $score == 0) || !$this->room()) {
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
        $body = Otlp::fields($body, $this->redactor, $this->options->maxValueLength);
        $id = Ids::new(16);
        // The app's own configuration: cut, not redacted.
        $body += Otlp::cut(array_filter([
            'sdk' => self::sdk(),
            'feedback_id' => $id,
            'timestamp' => microtime(true),
            'source' => $f->source ?? 'api',
            'environment' => $this->options->environment,
            'trace_id' => $f->traceId ?? $span?->traceId,
            'event_id' => $f->eventId,
            'release' => $this->options->release,
        ], static fn($v) => $v !== null), $this->options->maxValueLength);
        $this->requests[] = ['/v1/feedback', self::FEEDBACK, $body];

        return $id;
    }

    /**
     * Sends what was captured, within $timeout seconds (the timeout option when null); false when
     * something could not be sent. Never throws.
     */
    public function flush(?float $timeout = null): bool
    {
        if ($this->dsn === null) {
            return true;
        }
        try {
            return $this->doFlush(microtime(true) + ($timeout ?? $this->options->timeout));
        } catch (\Throwable $ex) {
            $this->log('flushing failed: ' . $ex->getMessage());

            return false;
        }
    }

    private function doFlush(float $deadline): bool
    {
        $this->ownQueue();
        $ok = true;
        foreach ($this->sessions?->take($this->options) ?? [] as $session) {
            $this->requests[] = ['/v1/sessions', self::SESSION, $session];
        }
        $records = $this->records;
        $spans = $this->spans;
        $requests = $this->requests;
        $this->records = $this->spans = $this->requests = [];
        foreach ($this->batches($records, Otlp::logs($this->options, [])) as $batch) {
            $ok = $this->send('/v1/logs', self::ERROR, fn(): array => Otlp::logs($this->options, $batch), $deadline) && $ok;
        }
        foreach ($this->batches($spans, Otlp::traces($this->options, [])) as $batch) {
            $ok = $this->send('/v1/traces', self::SPAN, fn(): array => Otlp::traces($this->options, $batch), $deadline) && $ok;
        }
        foreach ($requests as [$path, $category, $body]) {
            $ok = $this->send($path, $category, static fn(): array => $body, $deadline) && $ok;
        }

        return $ok;
    }

    /**
     * One request, never throwing: a failure is logged (with the debug option) and the data dropped.
     *
     * @param \Closure(): array<string, mixed> $body
     */
    private function send(string $path, string $category, \Closure $body, float $deadline): bool
    {
        try {
            return $this->post($path, $category, $body(), $deadline);
        } catch (\Throwable $ex) {
            $this->log("sending to {$path} failed: " . $ex->getMessage());

            return false;
        }
    }

    private function room(): bool
    {
        $this->ownQueue();
        if (\count($this->records) + \count($this->spans) + \count($this->requests) >= $this->options->maxQueue) {
            $this->log('dropping: the queue is full');

            return false;
        }

        return true;
    }

    /**
     * Log records or spans in requests of at most BATCH of them and BATCH_BYTES of JSON, the
     * export around them included (each measured once), so that one request too large for Fixwire
     * doesn't lose the rest. An item that can't fit in a request of its own is dropped alone.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $empty the export without items
     *
     * @return list<list<array<string, mixed>>>
     */
    private function batches(array $items, array $empty): array
    {
        $room = self::BATCH_BYTES - \strlen((string) json_encode($empty, self::JSON));
        $batches = [];
        $batch = [];
        $bytes = 0;
        foreach ($items as $item) {
            $size = \strlen((string) json_encode($item, self::JSON)) + 1; // and a comma
            if ($size > $room) {
                $this->log('dropped a record or span: over 5 MB alone');
                continue;
            }
            if ($batch !== [] && (\count($batch) === self::BATCH || $bytes + $size > $room)) {
                $batches[] = $batch;
                $batch = [];
                $bytes = 0;
            }
            $batch[] = $item;
            $bytes += $size;
        }
        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * Sends one request now, by $deadline, honouring rate limits. A request without an answer, or
     * with a 5xx, is tried once more BACKOFF later (unless the answer paused it, or that would pass
     * the deadline): PHP sends at exit, where more tries would hold the app up. Still no answer
     * pauses sending to Fixwire, for every worker of the server (see Unreachable).
     *
     * @param array<string, mixed> $body
     */
    private function post(string $path, string $category, array $body, float $deadline): bool
    {
        if ($this->dsn === null || $this->paused($category)) {
            $this->log("dropping a {$category} request: paused");

            return false;
        }
        $json = json_encode($body, self::JSON);
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
        $url = $this->dsn->url($path);
        if (!$this->unreachable->allows(microtime(true), $deadline)) {
            $this->log("dropping a {$category} request: Fixwire did not answer lately");

            return false;
        }
        for ($attempt = 0; ; $attempt++) {
            $left = $deadline - microtime(true);
            if ($left <= 0.001) {
                if ($attempt > 0) {
                    break; // no time left for the retry: as without one
                }
                $this->log("dropping a {$category} request: out of time");

                return false;
            }
            [$status, $answer] = $this->transport instanceof HttpTransport
                ? $this->transport->sendWithin($url, $json, $headers, $left)
                : $this->transport->send($url, $json, $headers);
            if ($status !== 0) {
                $this->unreachable->answered();
            }
            $this->limit($answer['fixwire-rate-limits'] ?? null, $status, $answer['retry-after'] ?? null);
            if ($status >= 200 && $status < 300) {
                return true;
            }
            if ($status !== 0 && $status < 500) {
                $this->log("{$category} request refused: {$status}");

                return false;
            }
            // A 503 with Retry-After: not before then.
            if ($attempt === 1 || $this->paused($category) || microtime(true) + self::BACKOFF >= $deadline) {
                break;
            }
            usleep((int) (self::BACKOFF * 1e6));
        }
        if ($status === 0) {
            $this->unreachable->failed(microtime(true));
        }
        $this->log("dropping a {$category} request ({$status}" . (isset($answer['error']) ? ': ' . $answer['error'] : '') . ')');

        return false;
    }

    private function paused(string $category): bool
    {
        $now = microtime(true);
        foreach ([self::$paused[$this->project] ?? [], $this->sharedPauses()] as $paused) {
            if (($paused[$category] ?? 0) > $now || ($paused[''] ?? 0) > $now) {
                return true;
            }
        }

        return false;
    }

    /**
     * The project's pauses the PHP-FPM workers of this server share through APCu.
     *
     * @return array<string, float> category ("" for all) → paused until
     */
    private function sharedPauses(): array
    {
        if (!\function_exists('apcu_fetch') || !\function_exists('apcu_enabled') || !apcu_enabled()) {
            return [];
        }
        $stored = apcu_fetch(self::SHARED_PAUSES . $this->project);
        $out = [];
        foreach (\is_array($stored) ? $stored : [] as $category => $until) {
            if (\is_string($category) && (\is_float($until) || \is_int($until))) {
                $out[$category] = (float) $until;
            }
        }

        return $out;
    }

    /**
     * Reads Fixwire-Rate-Limits ("<seconds>:<category;…>, …"; no categories means all; categories
     * Fixwire doesn't name are ignored), a 429 without it (Retry-After, at least MIN_429_PAUSE, for
     * all) and a 5xx's Retry-After (for all). Seconds count up to MAX_PAUSE; broken ones are
     * ignored.
     */
    private function limit(?string $header, int $status, ?string $retryAfter): void
    {
        $now = microtime(true);
        $pauses = []; // category ("" for all) → seconds
        foreach ($header === null ? [] : explode(',', $header) as $part) {
            $sc = explode(':', trim($part), 2);
            $secs = self::seconds($sc[0]);
            if ($secs === null || $secs === 0) {
                continue;
            }
            $cats = trim($sc[1] ?? '');
            foreach ($cats === '' ? [''] : array_intersect(array_map('trim', explode(';', $cats)), self::CATEGORIES) as $cat) {
                $pauses[$cat] = max($pauses[$cat] ?? 0, $secs);
            }
        }
        $after = $retryAfter === null ? null : self::retryAfter($retryAfter, $now);
        if ($header === null && $status === 429) {
            $pauses[''] = max($after ?? 0, self::MIN_429_PAUSE);
        } elseif ($status >= 500 && $after !== null && $after > 0) {
            $pauses[''] = max($pauses[''] ?? 0, $after);
        }
        if ($pauses === []) {
            return;
        }
        foreach ($pauses as $cat => $secs) {
            self::$paused[$this->project][$cat] = max(self::$paused[$this->project][$cat] ?? 0, $now + $secs);
        }
        if (\function_exists('apcu_store') && \function_exists('apcu_enabled') && apcu_enabled()) {
            // Shared with the other requests of this server, whose pauses of other categories stay.
            $shared = $this->sharedPauses();
            foreach (self::$paused[$this->project] as $cat => $until) {
                $shared[$cat] = max($shared[$cat] ?? 0, $until);
            }
            $shared = array_filter($shared, static fn(float $until): bool => $until > $now);
            if ($shared !== []) {
                apcu_store(self::SHARED_PAUSES . $this->project, $shared, (int) ceil(max($shared) - $now));
            }
        }
    }

    /** Whole seconds of a header, up to MAX_PAUSE; null when broken. */
    private static function seconds(string $s): ?int
    {
        $s = trim($s);
        if ($s === '' || !ctype_digit($s)) {
            return null;
        }

        return \strlen($s) > 6 ? self::MAX_PAUSE : min((int) $s, self::MAX_PAUSE);
    }

    /** Retry-After's seconds (a number, or an HTTP date from now), up to MAX_PAUSE; null when broken. */
    private static function retryAfter(string $value, float $now): ?int
    {
        $secs = self::seconds($value);
        if ($secs !== null) {
            return $secs;
        }
        // IMF-fixdate (Sun, 06 Nov 1994 08:49:37 GMT), as HTTP servers write it.
        $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', trim($value), new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return (int) min(max(0, ceil($date->getTimestamp() - $now)), self::MAX_PAUSE);
    }

    /** @internal forgets the rate limits and the pauses after no answer (tests) */
    public static function resetRateLimits(): void
    {
        self::$paused = [];
        Unreachable::reset();
        if (\function_exists('apcu_delete') && \function_exists('apcu_enabled') && apcu_enabled() && class_exists(\APCUIterator::class)) {
            apcu_delete(new \APCUIterator('/^' . preg_quote(self::SHARED_PAUSES, '/') . '/'));
        }
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
