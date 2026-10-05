<?php

declare(strict_types=1);

namespace Fixwire\Internal\Redaction;

/**
 * Masks secrets and personal data on the device before anything is sent,
 * with the same output as the Fixwire server's redaction (proven by the
 * shared corpus pkg/redact/testdata/vectors.json).
 *
 * Detectors run in a fixed order; a cheap prefilter skips each one on text
 * that cannot match, and validators (Luhn, mod-97, checksums) reject
 * look-alikes so trace ids, hashes and timestamps survive. Immutable.
 *
 * Text that is not valid UTF-8 is searched as json_encode with
 * JSON_INVALID_UTF8_SUBSTITUTE writes it: each invalid sequence as U+FFFD.
 *
 * @internal
 */
final class Redactor
{
    /** Replaces the value of a sensitive key. */
    public const FILTERED = '[Filtered]';

    /**
     * The detectors on by default, in the server's order: all but ipv4 (in
     * error messages IP addresses are usually servers worth seeing).
     *
     * @var list<string>
     */
    public const DEFAULT_DETECTORS = [
        'private_key', 'aws_access_key', 'gcp_api_key', 'azure_storage_key', 'github_token', 'stripe_key',
        'slack_token', 'slack_webhook', 'anthropic_key', 'openai_key', 'jwt', 'fixwire_secret_key', 'url_credentials',
        'http_auth', 'secret_assignment', 'email', 'credit_card', 'iban', 'us_ssn', 'tr_tckn', 'phone',
    ];

    /**
     * Key fragments whose values are always filtered whole.
     *
     * @var list<string>
     */
    public const DEFAULT_SENSITIVE_KEYS = [
        'password', 'passwd', 'pwd', 'secret', 'apikey', 'accesskey', 'token', 'credential', 'privatekey',
        'authorization', 'cookie', 'sessionid', 'csrf', 'xsrf', 'cvv', 'cvc', 'ssn', 'creditcard', 'cardnumber',
    ];

    /**
     * json_encode refuses containers nested deeper than its default depth,
     * so walk leaves them alone.
     */
    private const MAX_DEPTH = 512;

    private static ?self $default = null;

    /**
     * @param list<Detector> $detectors
     * @param list<string>   $keys      normalized key fragments
     */
    private function __construct(
        private readonly array $detectors,
        private readonly array $keys,
    ) {}

    /** The redactor with the default detectors and sensitive keys. */
    public static function default(): self
    {
        return self::$default ??= new self(Detectors::named(self::DEFAULT_DETECTORS), self::DEFAULT_SENSITIVE_KEYS);
    }

    /**
     * A redactor with the default detectors. Its sensitive keys replace the
     * defaults, compared like the server does (lower case, without "-", "_"
     * and spaces).
     *
     * @param list<string>|null $sensitiveKeys null = the defaults; else normalized like the server
     */
    public static function create(?array $sensitiveKeys = null): self
    {
        if ($sensitiveKeys === null) {
            return self::default();
        }
        $keys = [];
        foreach ($sensitiveKeys as $k) {
            $keys[] = self::normalizeKey($k);
        }

        return new self(Detectors::named(self::DEFAULT_DETECTORS), $keys);
    }

    /**
     * Masks the findings in s: each becomes "[REDACTED:<detector>]", as the
     * server writes it. Text without findings comes back unchanged.
     *
     * @return array{0: string, 1: list<string>} the masked text ("[REDACTED:<detector>]" exactly as the server) and each finding's detector
     */
    public function mask(string $s): array
    {
        if ($s === '') {
            return [$s, []];
        }
        $t = new Text(Text::scrub($s));
        [$starts, $ends, $names] = $this->find($t);
        if ($starts === []) {
            return [$s, []];
        }
        $out = '';
        $last = 0;
        foreach ($starts as $k => $start) {
            $out .= substr($t->s, $last, $start - $last) . '[REDACTED:' . $names[$k] . ']';
            $last = $ends[$k];
        }

        return [$out . substr($t->s, $last), $names];
    }

    /**
     * Masks every string in a JSON-like value and filters the values of
     * sensitive keys, by the server's rules: a typed attribute
     * ({"type": ..., "value": ...}) keeps its shape, a list of two holding a
     * sensitive key and a value is a pair, keys that count tokens are not
     * secrets, and keys that hold data are masked too (keys that mask alike
     * are numbered in code-point order: "[REDACTED:email] (2)").
     *
     * Arrays are lists (JSON arrays) when array_is_list holds, else maps;
     * \stdClass objects are maps, copied when something in them changes.
     * Strings are masked; everything else (numbers, booleans, null, other
     * objects) is left as it is, and so are containers deeper than
     * json_encode goes.
     *
     * @param int $count grows by the number of values masked
     *
     * @return mixed the masked value
     */
    public function walk(mixed $value, int &$count): mixed
    {
        return $this->walkValue($value, $count, 0);
    }

    /**
     * The non-overlapping findings in t, leftmost first; when two overlap,
     * the earlier detector wins. A detector whose pattern fails (PCRE's
     * limits) masks the whole text, so a failure never lets a value through.
     *
     * @return array{list<int>, list<int>, list<string>} the start, end and detector of each
     */
    private function find(Text $t): array
    {
        // The findings so far, sorted by start and disjoint.
        $starts = [];
        $ends = [];
        $names = [];
        foreach ($this->detectors as $d) {
            if (!$d->mayMatch($t)) {
                continue;
            }
            $spans = $d->spans($t);
            if ($spans === null) {
                return [[0], [\strlen($t->s)], [$d->name]];
            }
            $addStarts = [];
            $addEnds = [];
            $count = \count($starts);
            $p = 0;
            $lastEnd = -1;
            // Spans come leftmost first, so one pass checks them against the
            // earlier detectors' findings and against this one's last.
            for ($k = 0, $m = \count($spans); $k < $m; $k += 2) {
                $start = $spans[$k];
                $end = $spans[$k + 1];
                if ($start < $lastEnd) {
                    continue;
                }
                if ($d->validate !== null && !($d->validate)(substr($t->s, $start, $end - $start))) {
                    continue;
                }
                while ($p < $count && $ends[$p] <= $start) {
                    $p++;
                }
                if ($p < $count && $starts[$p] < $end) {
                    continue;
                }
                $addStarts[] = $start;
                $addEnds[] = $end;
                $lastEnd = $end;
            }
            if ($addStarts !== []) {
                [$starts, $ends, $names] = self::merge($starts, $ends, $names, $addStarts, $addEnds, $d->name);
            }
        }

        return [$starts, $ends, $names];
    }

    /**
     * The findings with one detector's added, still sorted by start.
     *
     * @param list<int>    $starts
     * @param list<int>    $ends
     * @param list<string> $names
     * @param list<int>    $addStarts
     * @param list<int>    $addEnds
     *
     * @return array{list<int>, list<int>, list<string>}
     */
    private static function merge(array $starts, array $ends, array $names, array $addStarts, array $addEnds, string $name): array
    {
        $s = [];
        $e = [];
        $n = [];
        $i = 0;
        $j = 0;
        $ni = \count($starts);
        $nj = \count($addStarts);
        while ($i < $ni || $j < $nj) {
            if ($j === $nj || ($i < $ni && $starts[$i] < $addStarts[$j])) {
                $s[] = $starts[$i];
                $e[] = $ends[$i];
                $n[] = $names[$i];
                $i++;
            } else {
                $s[] = $addStarts[$j];
                $e[] = $addEnds[$j];
                $n[] = $name;
                $j++;
            }
        }

        return [$s, $e, $n];
    }

    /** A key as the server compares it: lower case, without "-", "_" and spaces. */
    private static function normalizeKey(string $k): string
    {
        return str_replace(['-', '_', ' '], '', Text::lowerKey(Text::scrub($k)));
    }

    /** Whether a key's value must be filtered whole. */
    private function sensitive(string $key): bool
    {
        $k = self::normalizeKey($key);
        if ($k === 'auth') {
            return true;
        }
        foreach ($this->keys as $frag) {
            if (str_contains($k, $frag) && ($frag !== 'token' || !self::tokenCount($k))) {
                return true;
            }
        }

        return false;
    }

    /** Keys that count model tokens rather than hold one: gen_ai.usage.input_tokens, max_tokens, token_count. */
    private static function tokenCount(string $k): bool
    {
        return str_ends_with($k, 'tokens') || str_contains($k, 'tokencount') || str_contains($k, 'usage');
    }

    private function walkValue(mixed $v, int &$n, int $depth): mixed
    {
        if (\is_string($v)) {
            [$masked, $fs] = $this->mask($v);
            $n += \count($fs);

            return $masked;
        }
        if ($depth >= self::MAX_DEPTH) {
            return $v;
        }
        if (\is_array($v)) {
            return array_is_list($v) ? $this->walkList($v, $n, $depth) : $this->walkMap($v, $n, $depth);
        }
        if ($v instanceof \stdClass) {
            $before = $n;
            $map = $this->walkMap(get_object_vars($v), $n, $depth);

            return $n === $before ? $v : (object) $map;
        }

        return $v;
    }

    // Lists and maps are walked into new arrays: writing into a copy would
    // write through any PHP references it holds, into the caller's data.

    /**
     * @param list<mixed> $list
     *
     * @return list<mixed>
     */
    private function walkList(array $list, int &$n, int $depth): array
    {
        // Some maps are sent as [key, value] pairs (headers, tags).
        if (\count($list) === 2 && \is_string($list[0]) && $this->sensitive($list[0]) && !self::isEmpty($list[1])) {
            $n++;

            return [$list[0], self::FILTERED];
        }
        $out = [];
        foreach ($list as $item) {
            $out[] = $this->walkValue($item, $n, $depth + 1);
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $map
     *
     * @return array<array-key, mixed>
     */
    private function walkMap(array $map, int &$n, int $depth): array
    {
        $out = [];
        /** @var list<array{string, int|string, string, int}> $renamed key, original key, masked key, findings */
        $renamed = [];
        foreach ($map as $k => $val) {
            $key = (string) $k;
            [$masked, $fs] = $this->mask($key);
            if ($fs !== []) {
                $renamed[] = [$key, $k, $masked, \count($fs)];
            }
            if (!$this->sensitive($key) || self::isEmpty($val)) {
                $out[$k] = $this->walkValue($val, $n, $depth + 1);
                continue;
            }
            // A typed attribute ({"type": ..., "value": ...}) keeps its shape.
            if ($val instanceof \stdClass && isset($val->value)) {
                if ($val->value !== self::FILTERED) {
                    $val = (object) array_replace(get_object_vars($val), ['value' => self::FILTERED, 'type' => 'string']);
                    $n++;
                }
            } elseif (\is_array($val) && isset($val['value'])) {
                if ($val['value'] !== self::FILTERED) {
                    $val = array_replace($val, ['value' => self::FILTERED, 'type' => 'string']);
                    $n++;
                }
            } elseif ($val !== self::FILTERED) {
                $val = self::FILTERED;
                $n++;
            }
            $out[$k] = $val;
        }
        if ($renamed === []) {
            return $out;
        }

        // Keys hold data too ({"ada@example.com": 3}). Keys that mask alike
        // are numbered in code-point order (byte order of UTF-8), each
        // taking the first name no key holds at its turn:
        // "[REDACTED:email] (2)".
        usort($renamed, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
        $taken = array_fill_keys(array_keys($out), true);
        $names = [];
        foreach ($renamed as [, $k, $masked, $count]) {
            $key = $masked;
            for ($i = 2; isset($taken[$key]); $i++) {
                $key = $masked . ' (' . $i . ')';
            }
            unset($taken[$k]);
            $taken[$key] = true;
            $names[$k] = $key;
            $n += $count;
        }
        // The same order, with each renamed key where it was.
        $renamedOut = [];
        foreach ($out as $k => $val) {
            $renamedOut[$names[$k] ?? $k] = $val;
        }

        return $renamedOut;
    }

    private static function isEmpty(mixed $v): bool
    {
        return $v === null || $v === '';
    }
}
