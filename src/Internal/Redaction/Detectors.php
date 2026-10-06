<?php

declare(strict_types=1);

namespace Fixwire\Internal\Redaction;

/**
 * The detectors of the Fixwire server's redaction, in its order, with the
 * same patterns, prefilters, validators and scanners.
 *
 * The server's patterns are ASCII-only, but PHP compiles "u" patterns with
 * Unicode properties, so \d, \s, \w and \b would take in other scripts (and
 * the locale can change them). Digits are [0-9], whitespace is [\t\n\f\r ]
 * and the word boundary is spelled out as lookarounds on ASCII word
 * characters. Nothing ignores case through the "i" flag: where the server
 * ignores case, each letter is written as a class, with the Kelvin sign next
 * to "k" and the long s next to "s" as the server folds them. Atomic groups
 * stand where the next token cannot match what they took: the same matches
 * without backtracking.
 *
 * The server scans bytes in its hand-written scanners and so do these; with
 * "u" the patterns count code points as the server's do.
 *
 * Two of the server's patterns are scanners here (private keys, URL
 * credentials): they find the same matches, but a backtracking engine would
 * take quadratic time on some text.
 *
 * @internal
 */
final class Detectors
{
    public const IPV4 = 'ipv4';

    /** The shapes of a number run (bits): a card's length, an SSN's groups, a TCKN's length. */
    public const CARD = 1;

    public const SSN = 2;

    public const TCKN = 4;

    private const WORD = '[0-9A-Za-z_]';

    /** The server's word boundary before a word character. */
    private const START = '(?<!' . self::WORD . ')';

    /** The server's word boundary after a word character. */
    private const END = '(?!' . self::WORD . ')';

    /** The server's word boundary where either side may be a word character. */
    private const EDGE = '(?:(?<=' . self::WORD . ')(?!' . self::WORD . ')|(?<!' . self::WORD . ')(?=' . self::WORD . '))';

    private const WS = '[\t\n\f\r ]';

    /** The letters the server's case-insensitive classes add to [A-Za-z]: the long s and the Kelvin sign. */
    private const FOLDED = '\x{17F}\x{212A}';

    private const WORD_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_';

    private const LETTERS_UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const LETTERS_LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private const LETTERS = self::LETTERS_UPPER . self::LETTERS_LOWER;

    private const LOCAL_CHARS = self::WORD_CHARS . '.%+-';

    private const DOMAIN_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz.-';

    private const SCHEME_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz+.-';

    private const KEY_TYPE_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ ';

    private const KEY_LABEL = 'PRIVATE KEY-----';

    private const DIGITS = '0123456789';

    private const CARD_PREFIXES = [
        '4', '51', '52', '53', '54', '55', '2221', '2720', '34', '37', '6011', '65', '35', '36', '38', '300', '305', '62',
    ];

    /** @var list<Detector>|null */
    private static ?array $registry = null;

    /**
     * Every detector, in the server's order.
     *
     * @return list<Detector>
     */
    public static function registry(): array
    {
        return self::$registry ??= self::build();
    }

    /**
     * The named detectors, in the order given.
     *
     * @param list<string> $names
     *
     * @return list<Detector>
     */
    public static function named(array $names): array
    {
        $byName = [];
        foreach (self::registry() as $d) {
            $byName[$d->name] = $d;
        }
        $out = [];
        foreach ($names as $name) {
            if (!isset($byName[$name])) {
                throw new \InvalidArgumentException('redact: unknown detector "' . $name . '"');
            }
            $out[] = $byName[$name];
        }

        return $out;
    }

    /** @return list<Detector> */
    private static function build(): array
    {
        $s = self::START;
        $e = self::END;
        $ws = self::WS;
        $f = self::FOLDED;

        return [
            new Detector('private_key', ['PRIVATE KEY-----'], true, null, scan: self::privateKeySpans(...)),
            new Detector(
                'aws_access_key',
                ['AKIA', 'ASIA', 'ABIA', 'ACCA'],
                true,
                self::re($s . '(?:AKIA|ASIA|ABIA|ACCA)[0-9A-Z]{16}' . $e),
            ),
            new Detector('gcp_api_key', ['AIza'], true, self::re($s . 'AIza[0-9A-Za-z_\-]{35}')),
            new Detector(
                'azure_storage_key',
                ['accountkey='],
                false,
                self::re(self::anyCase('accountkey') . '=([A-Za-z0-9+/' . $f . ']{86}==)'),
                1,
            ),
            new Detector(
                'github_token',
                ['ghp_', 'gho_', 'ghu_', 'ghs_', 'ghr_', 'github_pat_'],
                true,
                self::re($s . '(?:gh[pousr]_(?>[A-Za-z0-9]{36,255})|github_pat_(?>[A-Za-z0-9_]{60,255}))' . $e),
            ),
            new Detector(
                'stripe_key',
                ['sk_live_', 'sk_test_', 'rk_live_', 'rk_test_', 'whsec_'],
                true,
                self::re($s . '(?:(?:sk|rk)_(?:live|test)_[0-9A-Za-z]{16,247}|whsec_[A-Za-z0-9+/=]{24,})'),
            ),
            new Detector('slack_token', ['xox'], true, self::re($s . 'xox[abposr]-[0-9A-Za-z-]{10,250}' . self::EDGE)),
            new Detector(
                'slack_webhook',
                ['hooks.slack.com/services/'],
                true,
                self::re('https://hooks\.slack\.com/services/T(?>[A-Z0-9]+)/B(?>[A-Z0-9]+)/[A-Za-z0-9]+'),
            ),
            new Detector(
                'anthropic_key',
                ['sk-ant-'],
                true,
                self::re($s . 'sk-ant-(?:api|admin)[0-9]{2}-[A-Za-z0-9_\-]{80,}'),
            ),
            new Detector(
                'openai_key',
                ['sk-'],
                true,
                self::re($s . 'sk-(?:(?:proj|svcacct|admin)-[A-Za-z0-9_\-]{40,}|[A-Za-z0-9]{20}T3BlbkFJ[A-Za-z0-9]{20})'),
            ),
            new Detector(
                'jwt',
                ['eyJ'],
                true,
                self::re($s . 'eyJ(?>[A-Za-z0-9_-]{8,})\.eyJ(?>[A-Za-z0-9_-]{8,})\.[A-Za-z0-9_-]{8,}'),
            ),
            new Detector(
                'fixwire_secret_key',
                ['_sk_live_', '_sk_test_'],
                true,
                self::re($s . '[a-z]{2,4}_sk_(?:live|test)_[0-9A-Za-z]{38}' . $e),
            ),
            // The password in scheme://user:password@host (the user stays).
            new Detector(
                'url_credentials',
                ['://'],
                true,
                null,
                validate: self::unmasked(...),
                scan: self::urlCredentialSpans(...),
            ),
            // Bearer and Basic credentials outside a header (messages, breadcrumbs).
            new Detector(
                'http_auth',
                ['bearer', 'basic'],
                false,
                self::re(
                    $s . '(?:' . self::anyCase('bearer') . '|' . self::anyCase('basic') . ')(?>' . $ws . '+)'
                    . '((?>[A-Za-z0-9._~+/\-' . $f . ']{12,})=*)',
                ),
                1,
                self::credentialLike(...),
            ),
            // A value given to a secret's name, in text, config and URLs. The
            // name may end a longer one (access_token, client_secret,
            // csrfToken, PHPSESSID, X-Amz-Signature); an OAuth code counts in
            // a query or fragment only. The name is bounded and nothing after
            // it can take back what an atomic group took, so each start costs
            // the same and the whole text is searched in linear time.
            new Detector(
                'secret_assignment',
                ['pass', 'pwd', 'secret', 'key', 'token', 'credential', 'sess', 'sig', 'code'],
                false,
                self::re(
                    '(?:' . self::anyCase('password') . '|' . self::anyCase('passwd') . '|' . self::anyCase('pwd')
                    . '|' . self::anyCase('secret') . '(?:[_-]?' . self::anyCase('key') . ')?'
                    . '|' . self::anyCase('private') . '[_-]?' . self::anyCase('key')
                    . '|' . self::anyCase('token')
                    . '|' . self::anyCase('api') . '[_-]?' . self::anyCase('key')
                    . '|' . self::anyCase('access') . '[_-]?' . self::anyCase('key')
                    . '|' . self::anyCase('credential') . self::anyCase('s') . '?'
                    . '|' . self::anyCase('sess') . '(?:' . self::anyCase('ion') . ')?[_-]?' . self::anyCase('id')
                    . '|' . self::anyCase('sig') . '(?:' . self::anyCase('nature') . ')?'
                    . '|[?&\#]' . self::anyCase('code') . ')'
                    . '(?>["\']?)(?>' . $ws . '*)[:=](?>' . $ws . '*)(?>["\']?)'
                    . '((?>[^\t\n\f\r "\',;&]{6,}))',
                ),
                1,
                self::unmasked(...),
            ),
            new Detector('email', ['@'], true, null, scan: self::emailSpans(...)),
            new Detector('credit_card', [], false, null, scan: self::cardSpans(...)),
            new Detector(
                'iban',
                [],
                false,
                self::re($s . '[A-Z]{2}[0-9]{2}(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,3})?' . $e),
                0,
                self::validIban(...),
            ),
            new Detector('us_ssn', ['-'], true, null, scan: self::ssnSpans(...)),
            new Detector('tr_tckn', [], false, null, scan: self::tcknSpans(...)),
            new Detector('phone', ['+'], true, self::re('\+[0-9](?:[ .\-()]?[0-9]){7,14}' . $e), 0, self::validPhone(...)),
            new Detector(
                self::IPV4,
                ['.'],
                true,
                self::re(
                    $s . '(?:(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\.){3}'
                    . '(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])' . $e,
                ),
            ),
        ];
    }

    /** A pattern over UTF-8 text, counting code points. */
    private static function re(string $pattern): string
    {
        return '#' . $pattern . '#u';
    }

    /**
     * A pattern for lower-case letters in any case, as the server folds them:
     * "k" also matches the Kelvin sign and "s" the long s.
     */
    private static function anyCase(string $letters): string
    {
        $upper = strtr($letters, self::LETTERS_LOWER, self::LETTERS_UPPER);
        $out = '';
        for ($i = 0, $n = \strlen($letters); $i < $n; $i++) {
            $c = $letters[$i];
            $out .= '[' . $c . $upper[$i] . ($c === 'k' ? '\x{212A}' : ($c === 's' ? '\x{17F}' : '')) . ']';
        }

        return $out;
    }

    public static function isWord(string $c): bool
    {
        return $c !== '' && str_contains(self::WORD_CHARS, $c);
    }

    public static function isDigit(string $c): bool
    {
        $o = \ord($c);

        return $o >= 48 && $o <= 57;
    }

    private static function isUpper(string $c): bool
    {
        $o = \ord($c);

        return $o >= 65 && $o <= 90;
    }

    private static function isLetter(string $c): bool
    {
        return $c !== '' && str_contains(self::LETTERS, $c);
    }

    // Validators.

    /** Rejects values a scrubber already replaced. */
    public static function unmasked(string $v): bool
    {
        return !str_starts_with($v, '[REDACTED') && $v !== Redactor::FILTERED;
    }

    /**
     * Tells a token from a word after "basic": it has a digit, a base64
     * symbol, or capitals past its first letter ("dXNlcjpwYXNz", but not
     * "Authentication").
     */
    public static function credentialLike(string $v): bool
    {
        if (strpbrk($v, '0123456789+/=') !== false) {
            return true;
        }
        $rest = substr($v, 1);

        return strpbrk($rest, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') !== false && strpbrk($rest, 'abcdefghijklmnopqrstuvwxyz') !== false;
    }

    /** Checks the length, a known issuer prefix and the Luhn sum. */
    public static function validCard(string $s): bool
    {
        $d = self::digits($s);
        $len = \strlen($d);
        if ($len < 13 || $len > 19) {
            return false;
        }
        $known = false;
        foreach (self::CARD_PREFIXES as $p) {
            if (str_starts_with($d, $p)) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            return false;
        }
        $sum = 0;
        $twice = false;
        for ($i = $len - 1; $i >= 0; $i--) {
            $n = \ord($d[$i]) - 48;
            if ($twice) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $twice = !$twice;
        }

        return $sum % 10 === 0;
    }

    /** Checks the length (15 to 34) and the mod-97 checksum. */
    public static function validIban(string $s): bool
    {
        $s = str_replace(' ', '', $s);
        $len = \strlen($s);
        if ($len < 15 || $len > 34) {
            return false;
        }
        // The remainder of the decimal number the letters spell (A = 10 ... Z = 35),
        // read from the fifth character round to the fourth.
        $rem = 0;
        for ($i = 0; $i < $len; $i++) {
            $c = $s[($i + 4) % $len];
            if (self::isDigit($c)) {
                $rem = ($rem * 10 + \ord($c) - 48) % 97;
            } elseif (self::isUpper($c)) {
                $rem = ($rem * 100 + \ord($c) - 55) % 97;
            } else {
                return false;
            }
        }

        return $rem === 1;
    }

    /** Rejects numbers the US never issues. */
    public static function validSsn(string $s): bool
    {
        $area = substr($s, 0, 3);
        $group = substr($s, 4, 2);
        $serial = substr($s, 7, 4);

        return $area !== '000' && $area !== '666' && $area[0] !== '9' && $group !== '00' && $serial !== '0000';
    }

    /** Checks the Turkish identity number's two check digits. */
    public static function validTckn(string $s): bool
    {
        if (\strlen($s) !== 11 || $s[0] === '0') {
            return false;
        }
        $d = [];
        for ($i = 0; $i < 11; $i++) {
            $d[] = \ord($s[$i]) - 48;
        }
        $odd = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
        $even = $d[1] + $d[3] + $d[5] + $d[7];
        if (((($odd * 7 - $even) % 10) + 10) % 10 !== $d[9]) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += $d[$i];
        }

        return $sum % 10 === $d[10];
    }

    /** Wants an international number of 8 to 15 digits. */
    public static function validPhone(string $s): bool
    {
        $n = \strlen(self::digits($s));

        return $n >= 8 && $n <= 15;
    }

    private static function digits(string $s): string
    {
        return (string) preg_replace('/[^0-9]+/', '', $s);
    }

    // Hand-written scanners, as the server's: they work on bytes, and every
    // byte they test for is ASCII, so a multi-byte character never matches.
    // Spans are flat lists: start, end, start, end ...

    /**
     * The runs of digits, optionally split by single spaces or dashes, that
     * stand alone as words, as the server's scanner finds them: a run starts
     * at a digit not after a word character, takes digits and single
     * separators (one kind per run) followed by a digit, and counts only when
     * no word character follows it. Only runs shaped like a card, an SSN or
     * a TCKN are kept: start, end and shape bits for each.
     *
     * @return list<int>
     */
    public static function numberRuns(string $s): array
    {
        $out = [];
        $n = \strlen($s);
        $i = strcspn($s, self::DIGITS);
        while ($i < $n) {
            if ($i > 0 && self::isWord($s[$i - 1])) {
                // Every digit of this run follows a word character.
                $i += strspn($s, self::DIGITS, $i);
                $i += strcspn($s, self::DIGITS, $i);
                continue;
            }
            $start = $i;
            $digits = 0;
            $sep = '';
            $groups = [];
            $group = 0;
            $j = $i;
            while (true) {
                $len = strspn($s, self::DIGITS, $j);
                $digits += $len;
                $group += $len;
                $j += $len;
                if ($j + 1 < $n
                    && ($s[$j] === ' ' || $s[$j] === '-')
                    && ($sep === '' || $sep === $s[$j])
                    && self::isDigit($s[$j + 1])) {
                    $sep = $s[$j];
                    if (\count($groups) < 3) {
                        $groups[] = $group;
                    }
                    $group = 0;
                    $j++;
                    continue;
                }
                break;
            }
            $groups[] = $group; // past three groups this list is never [3, 2, 4]
            if ($j === $n || !self::isWord($s[$j])) {
                $shape = ($digits >= 13 && $digits <= 19 ? self::CARD : 0)
                    | ($sep === '-' && $groups === [3, 2, 4] ? self::SSN : 0)
                    | ($sep === '' && $digits === 11 ? self::TCKN : 0);
                if ($shape !== 0) {
                    array_push($out, $start, $j, $shape);
                }
            }
            $i = $j + 1;
            if ($i < $n) {
                $i += strcspn($s, self::DIGITS, $i);
            }
        }

        return $out;
    }

    /** @return list<int> */
    private static function cardSpans(Text $t): array
    {
        return self::numberSpans($t, self::CARD, self::validCard(...));
    }

    /** @return list<int> */
    private static function ssnSpans(Text $t): array
    {
        return self::numberSpans($t, self::SSN, self::validSsn(...));
    }

    /** @return list<int> */
    private static function tcknSpans(Text $t): array
    {
        return self::numberSpans($t, self::TCKN, self::validTckn(...));
    }

    /**
     * The number runs of one shape that pass the validator.
     *
     * @param \Closure(string): bool $valid
     *
     * @return list<int>
     */
    private static function numberSpans(Text $t, int $shape, \Closure $valid): array
    {
        $runs = $t->numbers();
        $out = [];
        for ($k = 0, $m = \count($runs); $k < $m; $k += 3) {
            if (($runs[$k + 2] & $shape) !== 0 && $valid(substr($t->s, $runs[$k], $runs[$k + 1] - $runs[$k]))) {
                $out[] = $runs[$k];
                $out[] = $runs[$k + 1];
            }
        }

        return $out;
    }

    /**
     * Grows outwards from each "@" over the characters an address may hold,
     * and keeps it if the domain ends in a dotted, alphabetic TLD.
     *
     * @return list<int>
     */
    private static function emailSpans(Text $t): array
    {
        $s = $t->s;
        $n = \strlen($s);
        $out = [];
        for ($i = strpos($s, '@'); $i !== false; $i = strpos($s, '@', $i + 1)) {
            // The local part, read leftwards in the reversed string.
            $start = $i - strspn($t->reversed(), self::LOCAL_CHARS, $n - $i);
            $end = $i + 1 + strspn($s, self::DOMAIN_CHARS, $i + 1);
            while ($end > $i + 1 && ($s[$end - 1] === '.' || $s[$end - 1] === '-')) {
                $end--;
            }
            if ($start >= $i || $end - $i - 1 < 3) {
                continue; // no local part, or no room for "x.yz"
            }
            $domain = substr($s, $i + 1, $end - $i - 1);
            $dot = strrpos($domain, '.');
            if ($dot === false || $dot === 0) {
                continue;
            }
            $tld = \strlen($domain) - $dot - 1;
            $ok = $tld >= 2 && $tld <= 24 && strspn($domain, self::LETTERS, $dot + 1) === $tld;
            while ($start < $i && ($s[$start] === '.' || $s[$start] === '-')) {
                $start++;
            }
            if ($ok && $start < $i) {
                array_push($out, $start, $end);
            }
        }

        return $out;
    }

    // Scanners for two of the server's patterns, finding the same leftmost
    // matches in linear time.

    /**
     * The server's -----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----[\s\S]*?-----END (?:[A-Z ]+ )?PRIVATE KEY-----:
     * each BEGIN line with the first END line after it.
     *
     * @return list<int>
     */
    private static function privateKeySpans(Text $t): array
    {
        $s = $t->s;
        $out = [];
        $from = 0;
        while (($begin = strpos($s, '-----BEGIN ', $from)) !== false) {
            $head = self::keyLabelEnd($s, $begin + 11);
            if ($head < 0) {
                $from = $begin + 1;
                continue;
            }
            $end = -1;
            $line = strpos($s, '-----END ', $head);
            while ($line !== false && ($end = self::keyLabelEnd($s, $line + 9)) < 0) {
                $line = strpos($s, '-----END ', $line + 1);
            }
            if ($end < 0) {
                break; // a later BEGIN line finds no END line either
            }
            array_push($out, $begin, $end);
            $from = $end;
        }

        return $out;
    }

    /**
     * The end of (?:[A-Z ]+ )?PRIVATE KEY----- at i, or -1. "PRIVATE KEY" can
     * only end the run of capitals and spaces from i, so there is one place
     * to look.
     */
    private static function keyLabelEnd(string $s, int $i): int
    {
        $label = $i + strspn($s, self::KEY_TYPE_CHARS, $i) - 11;
        if ($label < $i || substr($s, $label, 16) !== self::KEY_LABEL) {
            return -1;
        }
        if ($label > $i && ($label < $i + 2 || $s[$label - 1] !== ' ')) {
            return -1; // the type before the label needs a letter or space and then a space
        }

        return $label + 16;
    }

    /**
     * The password of the server's \b[A-Za-z][A-Za-z0-9+.\-]*://[^\s/?#@:]*:([^\s/?#@]+)@.
     * Every start in the scheme before one "://" shares the rest of the
     * match, so only the first is tried.
     *
     * @return list<int>
     */
    private static function urlCredentialSpans(Text $t): array
    {
        $s = $t->s;
        $n = \strlen($s);
        $out = [];
        $from = 0;
        $sep = strpos($s, '://');
        while ($sep !== false) {
            // The scheme characters before "://", back to the last match.
            $scheme = $sep - strspn($t->reversed(), self::SCHEME_CHARS, $n - $sep, $sep - $from);
            // The first letter at a word boundary starts the scheme.
            while ($scheme < $sep && !(self::isLetter($s[$scheme]) && ($scheme === 0 || !self::isWord($s[$scheme - 1])))) {
                $scheme++;
            }
            if ($scheme < $sep) {
                $user = $sep + 3 + strcspn($s, ":\t\n\f\r /?#@", $sep + 3);
                if ($user < $n && $s[$user] === ':') {
                    $end = $user + 1 + strcspn($s, "\t\n\f\r /?#@", $user + 1);
                    if ($end > $user + 1 && $end < $n && $s[$end] === '@') {
                        array_push($out, $user + 1, $end);
                        $from = $end + 1;
                        $sep = strpos($s, '://', $from);
                        continue;
                    }
                }
            }
            $sep = strpos($s, '://', $sep + 1);
        }

        return $out;
    }
}
