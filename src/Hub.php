<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * Pairs a client with a stack of scopes and the current span. The functions in the Fixwire
 * namespace use the current hub.
 */
final class Hub
{
    private static ?Hub $current = null;

    private static ?string $lastEventId = null;

    /** @var list<Scope> */
    private array $scopes;

    private ?Span $span = null;

    public function __construct(private ?Client $client = null, ?Scope $scope = null)
    {
        $this->scopes = [$scope ?? new Scope()];
    }

    /** The hub the functions in the Fixwire namespace use. */
    public static function current(): self
    {
        return self::$current ??= new self();
    }

    /** Makes a hub the current one (for tests and runtimes that serve several apps). */
    public static function setCurrent(self $hub): void
    {
        self::$current = $hub;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function bindClient(?Client $client): void
    {
        $this->client = $client;
    }

    /** The current scope. */
    public function getScope(): Scope
    {
        return $this->scopes[\count($this->scopes) - 1];
    }

    /**
     * Runs something with a copy of the current scope: what it sets there is gone afterwards.
     *
     * @template T
     *
     * @param callable(Scope): T $work
     *
     * @return T
     */
    public function withScope(callable $work): mixed
    {
        $scope = clone $this->getScope();
        $this->scopes[] = $scope;
        try {
            return $work($scope);
        } finally {
            array_pop($this->scopes);
        }
    }

    /** The current span, or null. */
    public function getSpan(): ?Span
    {
        return $this->span;
    }

    /** @internal */
    public function setSpan(?Span $span): void
    {
        $this->span = $span;
    }

    /** Sends a throwable and its previous ones; its event id, or null when not sent. */
    public function captureException(\Throwable $exception, string $mechanism = 'generic', bool $handled = true, ?Level $level = null): ?string
    {
        $client = $this->client;
        if ($client === null || !$client->isEnabled()) {
            return null;
        }
        $e = new Event();
        $e->throwable = $exception;
        $e->exceptions = Frames::chain($exception, $mechanism, $handled, $client->options());
        $e->level = $level ?? ($handled ? null : Level::Fatal);

        return self::remember($client->capture($e, $this->getScope(), $this->span));
    }

    /** Sends a message (at the scope's level, else info); its event id, or null when not sent. */
    public function captureMessage(string $message, ?Level $level = null): ?string
    {
        $client = $this->client;
        if ($client === null || !$client->isEnabled()) {
            return null;
        }
        $e = new Event();
        $e->message = $message;
        $e->level = $level;

        return self::remember($client->capture($e, $this->getScope(), $this->span));
    }

    /** Sends an event as it is, with what the scope knows; its id, or null when not sent. */
    public function captureEvent(Event $event): ?string
    {
        $client = $this->client;
        if ($client === null || !$client->isEnabled()) {
            return null;
        }

        return self::remember($client->capture($event, $this->getScope(), $this->span));
    }

    /** The id of the last event sent, such as for a feedback form after a crash. */
    public static function lastEventId(): ?string
    {
        return self::$lastEventId;
    }

    private static function remember(?string $id): ?string
    {
        if ($id !== null) {
            self::$lastEventId = $id;
        }

        return $id;
    }

    /** Records something that happened. */
    public function addBreadcrumb(Breadcrumb $breadcrumb): void
    {
        $max = 100;
        $options = $this->client?->options();
        if ($options !== null) {
            $max = $options->maxBreadcrumbs;
            if ($options->beforeBreadcrumb !== null) {
                try {
                    $breadcrumb = ($options->beforeBreadcrumb)($breadcrumb);
                } catch (\Throwable) {
                    // keep the breadcrumb as it was
                }
                if ($breadcrumb === null) {
                    return;
                }
            }
        }
        $this->getScope()->addBreadcrumb($breadcrumb, $max);
    }

    /** Sends what someone said about an error or an AI answer; its id, or null when it holds neither a message nor a score. */
    public function captureFeedback(Feedback $feedback): ?string
    {
        $client = $this->client;

        return $client === null || !$client->isEnabled() ? null : $client->captureFeedback($feedback, $this->getScope(), $this->span);
    }

    /**
     * Starts a span under the current one (or a new trace) and makes it current until it ends.
     *
     * @param array<string, mixed> $attributes OpenTelemetry's semantic conventions
     */
    public function startSpan(string $name, ?string $op = null, array $attributes = [], ?SpanKind $kind = null): Span
    {
        return Span::start($this, $name, $op, $attributes, $kind);
    }

    /**
     * Starts a span that continues a caller's trace, from its W3C headers; a malformed traceparent
     * starts a new trace. The caller's sampling decision holds.
     *
     * @param array<string, mixed> $attributes
     */
    public function continueTrace(?string $traceparent, ?string $tracestate, ?string $baggage, string $name, ?string $op = null, array $attributes = []): Span
    {
        return Span::start($this, $name, $op, $attributes, null, $traceparent, $tracestate, $baggage);
    }

    /**
     * Starts the session of the request the scope serves, for release health; call the result when
     * the request ends. Integrations do this.
     *
     * @return \Closure(): void
     */
    public function startRequestSession(): \Closure
    {
        $client = $this->client;
        $sessions = $client?->sessions();
        if ($sessions === null) {
            return static function (): void {};
        }
        $rs = new RequestSession();
        $scope = $this->getScope();
        $scope->session = $rs;
        $ended = false;
        $hub = $this;

        return static function () use ($sessions, $rs, $scope, $hub, &$ended): void {
            if (!$ended) {
                $ended = true;
                // The user may have been set later, on a scope of the request's own.
                $user = $hub->getScope()->getUser() ?? $scope->getUser();
                $sessions->record($rs->status, Sessions::deviceId($user), microtime(true));
            }
        };
    }

    /** Sends what was captured; false when something could not be sent. */
    public function flush(): bool
    {
        return $this->client?->flush() ?? true;
    }
}
