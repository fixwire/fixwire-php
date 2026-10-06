<?php

declare(strict_types=1);

namespace Fixwire;

use Fixwire\Internal\Redaction\Redactor;

/**
 * @internal events and spans as OTLP JSON (sdks/PROTOCOL.md §3, §4), within the limits every
 * Fixwire SDK keeps (§13)
 */
final class Otlp
{
    /** The levels of lists and maps in a value, the items kept of each, and the items walked in one value. */
    private const MAX_DEPTH = 10;
    private const MAX_BREADTH = 100;
    private const MAX_WALKED = 10_000;

    /** What redaction reads past a string's cut, so that a secret the cut goes through is still found. */
    private const LOOKAHEAD = 16_384;

    /** The bytes of exception.stacktrace kept, instead of max_value_length. */
    private const MAX_STACKTRACE = 65_536;

    /** @return array<string, mixed> */
    public static function resource(Options $o): array
    {
        return ['attributes' => self::attributes(self::cut([
            'service.name' => $o->serviceName,
            'service.version' => $o->release,
            'deployment.environment.name' => $o->environment,
            'host.name' => $o->serverName,
            'telemetry.sdk.name' => Client::SDK_NAME,
            'telemetry.sdk.version' => Client::SDK_VERSION,
            'telemetry.sdk.language' => 'php',
        ], $o->maxValueLength))];
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
     * A span record with its strings redacted and cut, and its attributes as OTLP key-values.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    public static function span(array $record, ?Redactor $redactor, int $limit): array
    {
        /** @var array<string, mixed> $attrs */
        $attrs = $record['attributes'];
        $op = $attrs['fixwire.op'] ?? null;
        unset($attrs['fixwire.op']);
        $plain = self::fields($attrs, $redactor, $limit);
        $plain['fixwire.op'] = \is_string($op) ? self::cutString($op, $limit) : $op;
        $record['attributes'] = self::attributes($plain);
        $record['name'] = self::text((string) $record['name'], $redactor, $limit);
        if (isset($record['status']['message'])) {
            $record['status']['message'] = self::text((string) $record['status']['message'], $redactor, $limit);
        }

        return $record;
    }

    /**
     * An error or a message as a log record (sdks/PROTOCOL.md §4), redacted and cut. The app's
     * values (tags, contexts, extras, breadcrumbs' data) are each a value within the limits; the
     * lists the SDK makes (the exceptions, their frames, the breadcrumbs) are kept whole.
     *
     * @return array<string, mixed>
     */
    public static function eventRecord(Event $e, ?Redactor $redactor, int $limit): array
    {
        $window = $limit + self::LOOKAHEAD;
        $value = static fn(mixed $v): mixed => self::plain($v, $window);
        $a = [
            'fixwire.tags' => $value($e->tags),
            'fixwire.transaction' => $value($e->transaction),
            'fixwire.fingerprint' => $value($e->fingerprint),
        ];
        if ($e->suppressed > 0) {
            $a['fixwire.suppressed'] = $e->suppressed;
        }
        if ($e->user !== null) {
            $a['user.id'] = $value($e->user->id);
            $a['user.email'] = $value($e->user->email);
            $a['user.name'] = $value($e->user->username);
            $a['client.address'] = $value($e->user->ipAddress);
        }
        $contexts = [];
        foreach ($e->contexts as $name => $context) {
            $contexts[self::key($name, $window)] = $value($context);
        }
        $a['fixwire.contexts'] = $contexts;
        foreach ($e->extra as $k => $v) {
            $k = self::key($k, $window);
            if (!\array_key_exists($k, $a)) {
                $a[$k] = self::plain($v, $k === 'exception.stacktrace' ? self::MAX_STACKTRACE + self::LOOKAHEAD : $window);
            }
        }
        if ($e->breadcrumbs !== []) {
            $a['fixwire.breadcrumbs'] = array_map(static fn(Breadcrumb $b): array => [
                'timestamp' => $b->timestamp,
                'type' => $value($b->type),
                'category' => $value($b->category),
                'message' => $value($b->message),
                'level' => $b->level?->value,
                'data' => $value($b->data),
            ], $e->breadcrumbs);
        }
        if ($e->request !== null) {
            $r = $e->request;
            $a['http.request.method'] = $value($r->method);
            $a['url.full'] = $value($r->url);
            $a['url.query'] = $value($r->query);
            $a['http.route'] = $value($r->currentRoute());
            foreach ($r->headers as $name => $header) {
                $name = strtolower(self::key($name, $window));
                $a[$name === 'user-agent' ? 'user_agent.original' : 'http.request.header.' . $name] = $value($header);
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
            $record['body'] = self::value(self::text((string) $e->message, $redactor, $limit));
        } else {
            $record['eventName'] = 'exception';
            $outer = $e->exceptions[0];
            $a['exception.type'] = $value($outer->type);
            $a['exception.message'] = $value($outer->message);
            $chain = [];
            $handled = true;
            foreach ($e->exceptions as $x) {
                $frames = [];
                foreach ($x->frames as $f) {
                    $fm = ['function' => $value($f->function), 'module' => $value($f->module), 'file' => $value($f->file)];
                    if ($f->line > 0) {
                        $fm['line'] = $f->line;
                    }
                    $fm['in_app'] = $f->inApp;
                    if ($f->contextLine !== null) {
                        $fm['context_line'] = $value($f->contextLine);
                        $fm['pre_context'] = array_map($value, $f->preContext);
                        $fm['post_context'] = array_map($value, $f->postContext);
                    }
                    $frames[] = $fm;
                }
                $chain[] = [
                    'type' => $value($x->type),
                    'message' => $value($x->message),
                    'module' => $value($x->module),
                    'mechanism' => ['type' => $value($x->mechanism), 'handled' => $x->handled],
                    'frames' => $frames,
                ];
                $handled = $handled && $x->handled;
            }
            $a['fixwire.exceptions'] = $chain;
            if (!$handled) {
                $a['fixwire.handled'] = false;
            }
            if ($e->message !== null && $e->message !== '') {
                $record['body'] = self::value(self::text($e->message, $redactor, $limit));
            }
        }
        $plain = self::cut(self::scrub($a, $redactor), $limit);
        $plain['fixwire.event_id'] = $e->eventId;
        $record['attributes'] = self::attributes($plain);

        return $record;
    }

    /**
     * The app's values of a map (attributes, a JSON body), each within the limits, redacted, then
     * cut: redaction reads the next LOOKAHEAD bytes past each cut.
     *
     * @param array<array-key, mixed> $m
     *
     * @return array<string, mixed>
     */
    public static function fields(array $m, ?Redactor $redactor, int $limit): array
    {
        $out = [];
        foreach ($m as $k => $v) {
            $window = ($k === 'exception.stacktrace' ? self::MAX_STACKTRACE : $limit) + self::LOOKAHEAD;
            $out[self::key($k, $limit + self::LOOKAHEAD)] = self::plain($v, $window);
        }

        return self::cut(self::scrub($out, $redactor), $limit);
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

    /** A string as it is sent: redacted over the part kept and the LOOKAHEAD after it, then cut. */
    public static function text(string $s, ?Redactor $redactor, int $limit): string
    {
        return self::cutString(self::mask(self::window($s, $limit + self::LOOKAHEAD), $redactor), $limit);
    }

    /**
     * A value in JSON's own terms (arrays, strings, numbers, booleans and null): at most MAX_DEPTH
     * levels of MAX_BREADTH items each, MAX_WALKED items walked in all. A container inside itself
     * is "[Circular ~]", one deeper than the limit "[Object]" or "[Array]", one that can't be read
     * "[Unreadable]"; NaN and the infinities are "NaN", "Infinity" and "-Infinity". Strings keep
     * their first $window bytes, for redaction to read before they are cut.
     */
    public static function plain(mixed $v, int $window): mixed
    {
        $walked = 0;
        $parents = [];

        return self::normalize($v, 1, $window, $walked, $parents);
    }

    /** @param array<int, true> $parents the objects $v is in */
    private static function normalize(mixed $v, int $depth, int $window, int &$walked, array &$parents): mixed
    {
        if (\is_string($v)) {
            return self::window($v, $window);
        }
        if (\is_float($v) && !is_finite($v)) {
            return is_nan($v) ? 'NaN' : ($v > 0 ? 'Infinity' : '-Infinity');
        }
        if ($v === null || \is_scalar($v)) {
            return $v;
        }
        if ($v instanceof \BackedEnum) {
            return \is_string($v->value) ? self::window($v->value, $window) : $v->value;
        }
        if ($v instanceof \UnitEnum) {
            return $v->name;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d\TH:i:s.vP');
        }
        if (!\is_array($v) && !$v instanceof \Traversable && !$v instanceof \JsonSerializable && !$v instanceof \stdClass) {
            if (!$v instanceof \Stringable) {
                return \is_object($v) ? $v::class : get_debug_type($v);
            }
            try {
                return self::window((string) $v, $window);
            } catch (\Throwable) {
                return '[Unreadable]';
            }
        }
        $id = \is_object($v) ? spl_object_id($v) : null;
        if ($id !== null && isset($parents[$id])) {
            return '[Circular ~]';
        }
        if ($depth > self::MAX_DEPTH) {
            return \is_array($v) && array_is_list($v) ? '[Array]' : '[Object]';
        }
        if ($id !== null) {
            $parents[$id] = true;
        }
        try {
            if ($v instanceof \JsonSerializable) {
                $v = $v->jsonSerialize();
                if (!\is_array($v)) {
                    return self::normalize($v, $depth + 1, $window, $walked, $parents);
                }
            }
            $out = [];
            $n = 0;
            // An iterator may be lazy (a database cursor) or endless (a generator): read no further.
            foreach ($v instanceof \stdClass ? get_object_vars($v) : $v as $k => $item) {
                if ($n++ === self::MAX_BREADTH || $walked++ >= self::MAX_WALKED) {
                    break;
                }
                $out[\is_int($k) ? $k : self::key($k, $window)] = self::normalize($item, $depth + 1, $window, $walked, $parents);
            }

            return $out;
        } catch (\Throwable) {
            return '[Unreadable]';
        } finally {
            if ($id !== null) {
                unset($parents[$id]);
            }
        }
    }

    /** A key as a string, its first $window bytes; an iterator's may be anything. */
    private static function key(mixed $k, int $window): string
    {
        return match (true) {
            \is_string($k) => self::window($k, $window),
            \is_scalar($k) || $k === null => (string) $k,
            default => get_debug_type($k),
        };
    }

    /** The first $bytes of a string as valid UTF-8. */
    private static function window(string $s, int $bytes): string
    {
        if (\strlen($s) > $bytes) {
            $s = substr($s, 0, $bytes);
        }

        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }

    /**
     * Cuts each string of a map of plain values, and each key, to $limit bytes
     * (exception.stacktrace to MAX_STACKTRACE).
     *
     * @param array<array-key, mixed> $m
     *
     * @return array<string, mixed>
     */
    public static function cut(array $m, int $limit): array
    {
        $out = [];
        foreach ($m as $k => $v) {
            $out[self::cutString((string) $k, $limit)] = self::cutValue($v, $k === 'exception.stacktrace' ? self::MAX_STACKTRACE : $limit);
        }

        return $out;
    }

    private static function cutValue(mixed $v, int $limit): mixed
    {
        if (\is_string($v)) {
            return self::cutString($v, $limit);
        }
        if (!\is_array($v)) {
            return $v;
        }
        $out = [];
        foreach ($v as $k => $item) {
            $out[\is_string($k) ? self::cutString($k, $limit) : $k] = self::cutValue($item, $limit);
        }

        return $out;
    }

    /** A string of at most $limit bytes, cut on a character boundary and ending in "..." when it was longer. */
    public static function cutString(string $s, int $limit): string
    {
        if (\strlen($s) <= $limit) {
            return $s;
        }
        $end = $limit - 3;
        while ($end > 0 && (\ord($s[$end]) & 0xC0) === 0x80) {
            $end--; // within a character's UTF-8
        }

        return substr($s, 0, $end) . '...';
    }

    /**
     * OTLP key-values, empty values left out.
     *
     * @param array<string, mixed> $m plain values
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
     * A plain value as an OTLP AnyValue.
     *
     * @return array<string, mixed>
     */
    public static function value(mixed $v): array
    {
        return match (true) {
            $v === null => ['stringValue' => ''],
            \is_string($v) => ['stringValue' => $v],
            \is_bool($v) => ['boolValue' => $v],
            \is_int($v) => ['intValue' => (string) $v],
            \is_float($v) => is_finite($v) ? ['doubleValue' => $v] : ['stringValue' => is_nan($v) ? 'NaN' : ($v > 0 ? 'Infinity' : '-Infinity')],
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
