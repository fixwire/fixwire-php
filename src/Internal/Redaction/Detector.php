<?php

declare(strict_types=1);

namespace Fixwire\Internal\Redaction;

/**
 * Finds one kind of sensitive value. A cheap literal prefilter skips the
 * pattern on text that cannot match, and a validator rejects look-alikes
 * (Luhn, mod-97, checksums), so trace ids, hashes and timestamps survive.
 *
 * @internal
 */
final class Detector
{
    /**
     * @param list<string>                               $prefilter     substrings one of which must appear (in
     *                                                                  any case, unless caseSensitive); empty
     *                                                                  means always run
     * @param string|null                                $pattern       a PCRE pattern, when there is no scanner
     * @param int                                        $group         the group of the pattern to mask (0: the
     *                                                                  whole match)
     * @param (\Closure(string): bool)|null              $validate      rejects look-alikes; null accepts all
     * @param (\Closure(Text): list<int>)|null            $scan          replaces the pattern when set; it
     *                                                                  returns spans as start, end, start ...
     */
    public function __construct(
        public readonly string $name,
        public readonly array $prefilter,
        public readonly bool $caseSensitive,
        public readonly ?string $pattern,
        public readonly int $group = 0,
        public readonly ?\Closure $validate = null,
        public readonly ?\Closure $scan = null,
    ) {}

    /** Whether the prefilter lets t through to the pattern or scanner. */
    public function mayMatch(Text $t): bool
    {
        if ($this->prefilter === []) {
            return true;
        }
        $hay = $this->caseSensitive ? $t->s : $t->lower();
        foreach ($this->prefilter as $p) {
            if (str_contains($hay, $p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The candidate spans, leftmost first, as start, end, start, end ...,
     * from the scanner or the pattern (the configured group); null when the
     * pattern fails (PCRE's limits).
     *
     * @return list<int>|null
     */
    public function spans(Text $t): ?array
    {
        if ($this->scan !== null) {
            return ($this->scan)($t);
        }
        if ($this->pattern === null) {
            return null;
        }
        // A callback sees one match at a time, so many matches need little
        // memory, and the subject's UTF-8 is checked once. With
        // PREG_OFFSET_CAPTURE each group is [text, byte offset], offset -1
        // when the group did not take part.
        $out = [];
        $group = $this->group;
        $collect = static function (array $m) use (&$out, $group): string {
            $g = $group > 0 && isset($m[$group]) && $m[$group][1] >= 0 ? $m[$group] : $m[0];
            $out[] = $g[1];
            $out[] = $g[1] + \strlen($g[0]);

            return '';
        };
        $ok = preg_replace_callback($this->pattern, $collect, $t->s, -1, $count, PREG_OFFSET_CAPTURE);

        return $ok === null ? null : $out;
    }
}
