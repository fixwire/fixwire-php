<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * A timed piece of work in a trace. A span without a parent in the process (a request, a job) is a
 * segment: it is sent with the spans under it when it finishes (or at the end of the request).
 * While open it is the current span: errors captured meanwhile link to it, and spans started
 * meanwhile are its children.
 *
 *     $span = \Fixwire\startSpan('SELECT carts', 'db.query');
 *     try { ... } finally { $span->finish(); }
 */
final class Span
{
    /** The spans a segment keeps until it is sent. */
    public const MAX_CHILDREN = 1000;

    /** The longest tracestate or baggage passed on, in bytes: W3C's limit for baggage. */
    private const MAX_HEADER = 8192;

    public readonly string $traceId;

    public readonly string $spanId;

    public readonly ?string $parentSpanId;

    public readonly bool $sampled;

    public readonly ?string $tracestate;

    public readonly ?string $baggage;

    public readonly SpanKind $kind;

    public readonly float $start;

    private ?float $end = null;

    private bool $failed = false;

    private ?string $statusMessage = null;

    private bool $remoteParent = false;

    private Span $segment;

    /** @var list<Span> */
    private array $children = [];

    private bool $sent = false;

    private ?Span $previous;

    /**
     * @param array<string, mixed> $attributes
     */
    private function __construct(
        private Hub $hub,
        public string $name,
        public readonly ?string $op,
        private array $attributes,
        ?SpanKind $kind,
        ?Span $parent,
        ?string $traceparent,
        ?string $tracestate,
        ?string $baggage,
        ?float $startTime,
    ) {
        $this->spanId = Ids::new(8);
        $this->start = $startTime ?? microtime(true);
        $continued = $traceparent === null ? null : self::parseTraceparent($traceparent);
        if ($continued !== null) {
            [$this->traceId, $this->parentSpanId, $this->sampled] = $continued;
            $this->remoteParent = true;
            $this->tracestate = self::passOn($tracestate);
            $this->baggage = self::passOn($baggage);
            $this->segment = $this;
        } elseif ($parent !== null) {
            $this->traceId = $parent->traceId;
            $this->parentSpanId = $parent->spanId;
            $this->sampled = $parent->sampled;
            $this->tracestate = $parent->tracestate;
            $this->baggage = $parent->baggage;
            $this->segment = $parent->segment;
        } else {
            $this->traceId = Ids::new(16);
            $this->parentSpanId = null;
            $this->sampled = self::sample($this->traceId, $hub->getClient()?->options()->tracesSampleRate ?? 0.0);
            $this->tracestate = null;
            $this->baggage = null;
            $this->segment = $this;
        }
        $this->kind = $kind ?? self::kindOf($op);
        $this->previous = $hub->getSpan();
    }

    /**
     * @internal use \Fixwire\startSpan() or Hub::startSpan()
     *
     * @param array<string, mixed> $attributes
     * @param float|null           $startTime  when it started, in Unix seconds (now when null)
     */
    public static function start(Hub $hub, string $name, ?string $op, array $attributes, ?SpanKind $kind, ?string $traceparent = null, ?string $tracestate = null, ?string $baggage = null, bool $current = true, ?float $startTime = null): self
    {
        $span = new self($hub, $name, $op, $attributes, $kind, $traceparent === null ? $hub->getSpan() : null, $traceparent, $tracestate, $baggage, $startTime);
        if ($current) {
            $hub->setSpan($span);
        } else {
            $span->previous = null;
        }

        return $span;
    }

    /** @internal */
    public static function kindOf(?string $op): SpanKind
    {
        return match (true) {
            $op === null => SpanKind::Internal,
            $op === 'http.server' || str_ends_with($op, '.server') => SpanKind::Server,
            $op === 'http.client' || str_starts_with($op, 'db') || str_ends_with($op, '.client') => SpanKind::Client,
            str_ends_with($op, '.publish') => SpanKind::Producer,
            str_ends_with($op, '.process') => SpanKind::Consumer,
            default => SpanKind::Internal,
        };
    }

    /**
     * Decides a new trace the way every Fixwire SDK does: kept when its id's last 56 bits, as a
     * fraction of 2^56, are at least 1 - rate.
     *
     * @internal
     */
    public static function sample(string $traceId, float $rate): bool
    {
        if ($rate <= 0) {
            return false;
        }
        if ($rate >= 1) {
            return true;
        }
        $tail = substr($traceId, -14);
        if (\strlen($tail) !== 14 || !ctype_xdigit($tail)) {
            return false;
        }

        return hexdec($tail) / 2 ** 56 >= 1 - $rate;
    }

    /**
     * Reads 00-<trace id>-<parent id>-<flags>; null when malformed.
     *
     * @internal
     *
     * @return array{0: string, 1: string, 2: bool}|null
     */
    public static function parseTraceparent(string $header): ?array
    {
        $p = explode('-', trim($header));
        if (\count($p) < 4 || \strlen($p[0]) !== 2 || strtolower($p[0]) === 'ff' || \strlen($p[1]) !== 32 || \strlen($p[2]) !== 16 || \strlen($p[3]) !== 2) {
            return null;
        }
        if (!ctype_xdigit($p[0] . $p[1] . $p[2] . $p[3]) || trim($p[1], '0') === '' || trim($p[2], '0') === '') {
            return null;
        }

        return [strtolower($p[1]), strtolower($p[2]), (hexdec($p[3]) & 1) === 1];
    }

    /**
     * A caller's tracestate or baggage, to pass on as it came; null when it is too long, or holds
     * what would end a header (it may come from a queue's payload rather than a header).
     */
    private static function passOn(?string $header): ?string
    {
        return $header === null || \strlen($header) > self::MAX_HEADER || strpbrk($header, "\r\n\0") !== false ? null : $header;
    }

    /** The W3C traceparent header that continues this span's trace in a service it calls. */
    public function traceparent(): string
    {
        return '00-' . $this->traceId . '-' . $this->spanId . ($this->sampled ? '-01' : '-00');
    }

    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /** Marks the span failed. */
    public function setError(\Throwable|string|null $error = null): self
    {
        $this->failed = true;
        if ($error instanceof \Throwable) {
            $this->statusMessage = $error->getMessage();
            $this->attributes['error.type'] = $error::class;
        } elseif ($error !== null) {
            $this->statusMessage = $error;
        }

        return $this;
    }

    /** @internal */
    public function segmentName(): string
    {
        return $this->segment->name;
    }

    /**
     * Ends the span and makes the span before it current again. A segment is sent with the spans
     * finished under it; a span finishing after its segment was sent goes alone.
     *
     * @param float|null $endTime when it ended, in Unix seconds (now when null), for work timed elsewhere
     */
    public function finish(?float $endTime = null): void
    {
        if ($this->end !== null) {
            return;
        }
        $this->end = $endTime ?? microtime(true);
        if ($this->hub->getSpan() === $this) {
            $this->hub->setSpan($this->previous);
        }
        $client = $this->hub->getClient();
        if (!$this->sampled || $client === null || !$client->isEnabled()) {
            return;
        }
        if ($this->segment === $this) {
            $send = $this->children;
            $send[] = $this;
            $this->children = [];
            $this->sent = true;
            $client->queueSpans($send);
        } elseif ($this->segment->sent) {
            $client->queueSpans([$this]);
        } elseif (\count($this->segment->children) < self::MAX_CHILDREN) {
            $this->segment->children[] = $this;
        }
    }

    /**
     * @internal the span as the protocol sends it, attributes still plain
     *
     * @return array<string, mixed>
     */
    public function record(): array
    {
        $m = ['traceId' => $this->traceId, 'spanId' => $this->spanId];
        if ($this->parentSpanId !== null) {
            $m['parentSpanId'] = $this->parentSpanId;
        }
        $m['name'] = $this->name;
        $m['kind'] = $this->kind->value;
        $m['startTimeUnixNano'] = self::nanos($this->start);
        $m['endTimeUnixNano'] = self::nanos($this->end ?? microtime(true));
        $m['attributes'] = $this->attributes + ['fixwire.op' => $this->op];
        $status = ['code' => $this->failed ? 2 : 1];
        if ($this->failed && $this->statusMessage !== null) {
            $status['message'] = $this->statusMessage;
        }
        $m['status'] = $status;
        $m['flags'] = 0x100 | ($this->remoteParent ? 0x200 : 0) | ($this->sampled ? 1 : 0);

        return $m;
    }

    /** @internal */
    public static function nanos(float $seconds): string
    {
        $whole = (int) floor($seconds);
        $micros = (int) round(($seconds - $whole) * 1e6);

        return \sprintf('%d%06d000', $whole, min($micros, 999999));
    }
}
