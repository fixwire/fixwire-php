<?php

declare(strict_types=1);

namespace Fixwire\Internal\Redaction;

/**
 * One string being searched, with what several detectors share. Offsets are
 * byte offsets into valid UTF-8.
 *
 * @internal
 */
final class Text
{
    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private ?string $lower = null;

    private ?string $reversed = null;

    /** @var list<int>|null */
    private ?array $numbers = null;

    /** @param string $s valid UTF-8 (see scrub) */
    public function __construct(public readonly string $s) {}

    /**
     * The string as valid UTF-8: each invalid sequence becomes U+FFFD exactly
     * as json_encode with JSON_INVALID_UTF8_SUBSTITUTE replaces it, so the
     * text searched is the text the server receives.
     */
    public static function scrub(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $json = json_encode($s, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $out = \is_string($json) ? json_decode($json) : null;
        if (\is_string($out)) {
            return $out;
        }
        // Not reached: a string always encodes. Keep only the ASCII bytes.
        return (string) preg_replace('/[\x80-\xFF]/', '?', $s);
    }

    /**
     * The string in lower case for the prefilters. The prefilters are ASCII,
     * and on the server only A-Z, U+0130 ("i") and the Kelvin sign ("k")
     * lower-case to ASCII, so only those are mapped.
     */
    public function lower(): string
    {
        if ($this->lower === null) {
            $l = strtr($this->s, self::UPPER, self::LOWER);
            if (str_contains($l, "\u{130}") || str_contains($l, "\u{212A}")) {
                $l = str_replace(["\u{130}", "\u{212A}"], ['i', 'k'], $l);
            }
            $this->lower = $l;
        }

        return $this->lower;
    }

    /** The string backwards, for scanning to the left with strspn. */
    public function reversed(): string
    {
        return $this->reversed ??= strrev($this->s);
    }

    /**
     * The standalone runs of digits shaped like a card, SSN or TCKN, as
     * start, end and shape (Detectors::CARD, SSN, TCKN bits) per run.
     *
     * @return list<int>
     */
    public function numbers(): array
    {
        return $this->numbers ??= Detectors::numberRuns($this->s);
    }

    /** Lower-cases a key one code point at a time, as the server does. */
    public static function lowerKey(string $k): string
    {
        if (preg_match('/[\x80-\xFF]/', $k) === 1) {
            return mb_convert_case($k, MB_CASE_LOWER_SIMPLE, 'UTF-8');
        }

        return strtr($k, self::UPPER, self::LOWER);
    }
}
