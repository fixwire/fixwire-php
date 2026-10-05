<?php

declare(strict_types=1);

namespace Fixwire;

use Fixwire\Internal\Redaction\Redactor;

/**
 * @internal events and spans as OTLP JSON (sdks/PROTOCOL.md §3, §4)
 */
final class Otlp
{
    private const MAX_DEPTH = 10;

    /** @return array<string, mixed> */
    public static function resource(Options $o): array
    {
        return ['attributes' => self::attributes([
            'service.name' => $o->serviceName,
            'service.version' => $o->release,
            'deployment.environment.name' => $o->environment,
            'host.name' => $o->serverName,
            'telemetry.sdk.name' => Client::SDK_NAME,
            'telemetry.sdk.version' => Client::SDK_VERSION,
            'telemetry.sdk.language' => 'php',
        ])];
    }

    /** @return array<string, string> */
    private static function scope(): array
    {
        return ['name' => Client::SDK_NAME, 'version' => Client::SDK_VERSION];
    }

    /**
     * An OTLP logs export of records.
     *
     * @param list<array<string, mixed>> $records
     *
     * @return array<string, mixed>
     */
    public static function logs(Options $o, array $records): array
    {
        return ['resourceLogs' => [[
            'resource' => self::resource($o),
            'scopeLogs' => [['scope' => self::scope(), 'logRecords' => $records]],
        ]]];
    }

    /**
     * An OTLP traces export of span records (Span::record(), already redacted).
     *
     * @param list<array<string, mixed>> $spans
     *
     * @return array<string, mixed>
     */
    public static function traces(Options $o, array $spans): array
    {
        return ['resourceSpans' => [[
            'resource' => self::resource($o),
            'scopeSpans' => [['scope' => self::scope(), 'spans' => $spans]],
        ]]];
    }

    /**
     * A span record with its attributes redacted and as OTLP key-values.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    public static function span(array $record, ?Redactor $redactor): array
    {
        /** @var array<string, mixed> $attrs */
        $attrs = $record['attributes'];
        $op = $attrs['fixwire.op'] ?? null;
        unset($attrs['fixwire.op']);
        $plain = self::scrub(self::plainMap($attrs), $redactor);
        $plain['fixwire.op'] = $op;
        $record['attributes'] = self::attributes($plain);
        $record['name'] = self::mask((string) $record['name'], $redactor);

        return $record;
    }

    /**
     * An error or a message as a log record (sdks/PROTOCOL.md §4), redacted.
     *
     * @return array<string, mixed>
     */
    public static function eventRecord(Event $e, ?Redactor $redactor): array
    {
        $a = [
            'fixwire.tags' => $e->tags,
            'fixwire.transaction' => $e->transaction,
            'fixwire.fingerprint' => $e->fingerprint,
        ];
        if ($e->suppressed > 0) {
            $a['fixwire.suppressed'] = $e->suppressed;
        }
        if ($e->user !== null) {
            $a['user.id'] = $e->user->id;
            $a['user.email'] = $e->user->email;
            $a['user.name'] = $e->user->username;
            $a['client.address'] = $e->user->ipAddress;
        }
        $a['fixwire.contexts'] = $e->contexts;
        $a += $e->extra;
        if ($e->breadcrumbs !== []) {
            $a['fixwire.breadcrumbs'] = array_map(static fn(Breadcrumb $b): array => [
                'timestamp' => $b->timestamp,
                'type' => $b->type,
                'category' => $b->category,
                'message' => $b->message,
                'level' => $b->level?->value,
                'data' => $b->data,
            ], $e->breadcrumbs);
        }
        if ($e->request !== null) {
            $r = $e->request;
            $a['http.request.method'] = $r->method;
            $a['url.full'] = $r->url;
            $a['url.query'] = $r->query;
            $a['http.route'] = $r->currentRoute();
            foreach ($r->headers as $name => $value) {
                $name = strtolower((string) $name);
                $a[$name === 'user-agent' ? 'user_agent.original' : 'http.request.header.' . $name] = $value;
            }
        }
        $level = $e->level ?? Level::Error;
        $record = [
            'timeUnixNano' => Span::nanos($e->timestamp ?? microtime(true)),
            'severityNumber' => $level->severity(),
            'severityText' => strtoupper($level->value),
        ];
        if ($e->traceId !== null) {
            $record['traceId'] = $e->traceId;
            $record['spanId'] = $e->spanId;
        }
        if ($e->exceptions === []) {
            $record['eventName'] = 'fixwire.message';
            $record['body'] = self::value(self::mask((string) $e->message, $redactor));
        } else {
            $record['eventName'] = 'exception';
            $outer = $e->exceptions[0];
            $a['exception.type'] = $outer->type;
            $a['exception.message'] = $outer->message;
            $chain = [];
            $handled = true;
            foreach ($e->exceptions as $x) {
                $frames = [];
                foreach ($x->frames as $f) {
                    $fm = ['function' => $f->function, 'module' => $f->module, 'file' => $f->file];
                    if ($f->line > 0) {
                        $fm['line'] = $f->line;
                    }
                    $fm['in_app'] = $f->inApp;
                    if ($f->contextLine !== null) {
                        $fm['context_line'] = $f->contextLine;
                        $fm['pre_context'] = $f->preContext;
                        $fm['post_context'] = $f->postContext;
                    }
                    $frames[] = $fm;
                }
                $chain[] = [
                    'type' => $x->type,
                    'message' => $x->message,
                    'module' => $x->module,
                    'mechanism' => ['type' => $x->mechanism, 'handled' => $x->handled],
                    'frames' => $frames,
                ];
                $handled = $handled && $x->handled;
            }
            $a['fixwire.exceptions'] = $chain;
            if (!$handled) {
                $a['fixwire.handled'] = false;
            }
            if ($e->message !== null && $e->message !== '') {
                $record['body'] = self::value(self::mask($e->message, $redactor));
            }
        }
        $plain = self::scrub(self::plainMap($a), $redactor);
        $plain['fixwire.event_id'] = $e->eventId;
        $record['attributes'] = self::attributes($plain);

        return $record;
    }

    /**
     * @param array<string, mixed> $m
     *
     * @return array<string, mixed>
     */
    public static function scrub(array $m, ?Redactor $redactor): array
    {
        if ($redactor === null) {
            return $m;
        }
        $count = 0;
        $out = $redactor->walk($m, $count);

        return \is_array($out) ? $out : [];
    }

    public static function mask(string $s, ?Redactor $redactor): string
    {
        return $redactor === null || $s === '' ? $s : $redactor->mask($s)[0];
    }

    /**
     * @param array<array-key, mixed> $m
     *
     * @return array<string, mixed>
     */
    public static function plainMap(array $m): array
    {
        $out = [];
        foreach ($m as $k => $v) {
            $out[(string) $k] = self::plain($v, 1);
        }

        return $out;
    }

    /** A value in JSON's own terms: arrays, strings, numbers, booleans and null. */
    public static function plain(mixed $v, int $depth): mixed
    {
        if ($v === null || \is_scalar($v)) {
            return \is_string($v) ? self::utf8($v) : $v;
        }
        if ($v instanceof \BackedEnum) {
            return $v->value;
        }
        if ($v instanceof \UnitEnum) {
            return $v->name;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d\TH:i:s.vP');
        }
        if ($depth > self::MAX_DEPTH) {
            return '[too deep]';
        }
        if (\is_array($v) || $v instanceof \Traversable || $v instanceof \JsonSerializable || $v instanceof \stdClass) {
            if ($v instanceof \JsonSerializable) {
                $v = $v->jsonSerialize();
                if (!\is_array($v)) {
                    return self::plain($v, $depth + 1);
                }
            }
            $out = [];
            foreach ($v instanceof \Traversable ? iterator_to_array($v) : (array) $v as $k => $item) {
                $out[$k] = self::plain($item, $depth + 1);
            }

            return $out;
        }
        if ($v instanceof \Stringable) {
            return self::utf8((string) $v);
        }

        return \is_object($v) ? $v::class : get_debug_type($v);
    }

    private static function utf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }

    /**
     * OTLP key-values, empty values left out.
     *
     * @param array<string, mixed> $m
     *
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    public static function attributes(array $m): array
    {
        $out = [];
        foreach ($m as $k => $v) {
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            $out[] = ['key' => (string) $k, 'value' => self::value($v)];
        }

        return $out;
    }

    /**
     * A value as an OTLP AnyValue.
     *
     * @return array<string, mixed>
     */
    public static function value(mixed $v): array
    {
        $v = self::plain($v, 1);

        return match (true) {
            $v === null => ['stringValue' => ''],
            \is_string($v) => ['stringValue' => $v],
            \is_bool($v) => ['boolValue' => $v],
            \is_int($v) => ['intValue' => (string) $v],
            \is_float($v) => is_finite($v) ? ['doubleValue' => $v] : ['stringValue' => (string) $v],
            \is_array($v) && array_is_list($v) => ['arrayValue' => ['values' => array_map([self::class, 'value'], $v)]],
            \is_array($v) => ['kvlistValue' => ['values' => self::attributes(self::stringKeys($v))]],
            default => ['stringValue' => get_debug_type($v)],
        };
    }

    /**
     * @param array<array-key, mixed> $m
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $m): array
    {
        $out = [];
        foreach ($m as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
