<?php

declare(strict_types=1);

namespace Fixwire\Tests\Internal\Redaction;

use Fixwire\Internal\Redaction\Redactor;
use PHPUnit\Framework\TestCase;

/**
 * Cases beyond the shared corpus. Every expected value is what the Fixwire
 * server's redaction answers for the same input.
 */
final class RedactorTest extends TestCase
{
    public function testDefaultIsShared(): void
    {
        self::assertSame(Redactor::default(), Redactor::default());
        self::assertSame(Redactor::default(), Redactor::create());
        self::assertSame(['', []], Redactor::default()->mask(''));
        self::assertSame(['nothing to see', []], Redactor::default()->mask('nothing to see'));
    }

    public function testCaseFoldingAsTheServer(): void
    {
        // The Kelvin sign folds to "k" in the case-insensitive detectors and
        // lower-cases to "k" for their prefilters.
        self::assertMask("TO\u{212A}EN=[REDACTED:secret_assignment]", ['secret_assignment'], "TO\u{212A}EN=abcdefg");
        self::assertMask(
            "Account\u{212A}ey=[REDACTED:azure_storage_key]",
            ['azure_storage_key'],
            "Account\u{212A}ey=" . str_repeat('A', 86) . '==',
        );
        // The long s folds to "s", but stays itself in lower case, so the
        // prefilter needs another literal.
        self::assertMask("pa\u{17F}\u{17F}word=abcdefgh", [], "pa\u{17F}\u{17F}word=abcdefgh");
        self::assertMask("pa\u{17F}\u{17F}word=[REDACTED:secret_assignment] pwd", ['secret_assignment'], "pa\u{17F}\u{17F}word=abcdefgh pwd");
        self::assertMask("ba\u{17F}ic [REDACTED:http_auth] basic", ['http_auth'], "ba\u{17F}ic dXNlcj" . 'pwYXNz basic');
        // The long s is no ASCII word character: the word boundary before it
        // needs a word character on the left.
        self::assertMask("x\u{17F}ecret=[REDACTED:secret_assignment] token", ['secret_assignment'], "x\u{17F}ecret=abcdefgh token");
        self::assertMask("\u{17F}ecret=abcdefgh token", [], "\u{17F}ecret=abcdefgh token");
        // U+0130 lower-cases to "i" but folds to nothing.
        self::assertMask("BAS\u{130}C abcdefghijkl1", [], "BAS\u{130}C abcdefghijkl1");
        $count = 0;
        self::assertSame(['CREDENT' . "\u{130}" . 'AL' => '[Filtered]'], Redactor::default()->walk(["CREDENT\u{130}AL" => 'x'], $count));
        self::assertSame(1, $count);
    }

    public function testQuantifiersCountCodePoints(): void
    {
        self::assertMask("password=ab\u{1F600}cd", [], "password=ab\u{1F600}cd");
        self::assertMask('password=[REDACTED:secret_assignment]', ['secret_assignment'], "password=ab\u{1F600}cde");
    }

    public function testClassesAreAscii(): void
    {
        // Other scripts' digits are no digits, and a vertical tab is no space.
        self::assertMask(
            "1\u{663}\u{663}\u{663}\u{663}\u{663}\u{663}\u{663}\u{663}\u{663}\u{663}\u{663} [REDACTED:credit_card] \u{661}\u{662}\u{663}",
            ['credit_card'],
            "1\u{663}\u{663}\u{663}\u{663}\u{663}\u{663}\u{663}\u{663}\u{663}\u{663}\u{663} 4111111111111111 \u{661}\u{662}\u{663}",
        );
        self::assertMask("Bearer\vabcdefghijkl1 Bearer [REDACTED:http_auth]", ['http_auth'], "Bearer\vabcdefghijkl1 Bearer abcdefghijkl1");
        // An accented letter is no word character either side of a match.
        self::assertMask("\u{E9}[REDACTED:email]\u{E9}", ['email'], "\u{E9}ada@example.com\u{E9}");
    }

    public function testInvalidUtf8IsSearchedAsJsonWritesIt(): void
    {
        self::assertMask("user [REDACTED:email] \u{FFFD}", ['email'], "user ada@example.com \xFF");
        // Without findings the text comes back as it was.
        self::assertMask("plain \xFF text", [], "plain \xFF text");
        $count = 0;
        $out = Redactor::default()->walk(["ada@example.com\xFE" => "\xFF", 'k' => "\xC0"], $count);
        self::assertSame(["[REDACTED:email]\u{FFFD}" => "\xFF", 'k' => "\xC0"], $out);
        self::assertSame(1, $count);
    }

    public function testHostileInputsTakeLinearTime(): void
    {
        $begin = '-----BEGIN ' . 'RSA PRIVATE KEY-----';
        $inputs = [
            str_repeat('a.', 50000) . '://',
            str_repeat($begin . "\nMIIE\n", 3000),
            str_repeat('-----BEGIN ' . str_repeat('A', 100), 1000),
            'a://b:' . str_repeat(':', 100000),
            str_repeat('a://b:c', 14000),
            str_repeat('1-', 50000),
            str_repeat('1 ', 50000),
            str_repeat('a.', 50000) . str_repeat('@', 1000),
            str_repeat('xoxb-' . str_repeat('a', 300), 300),
            str_repeat('gh' . 'p_', 25000),
            'gh' . 'p_' . str_repeat('a', 100000),
            str_repeat('pwd: abc ', 11000),
            'password' . str_repeat(' ', 100000),
            str_repeat('Bearer ', 14000),
            str_repeat('AB12 ', 20000),
            str_repeat('+1 2 3 ', 14000),
            str_repeat('eyJ' . 'aaaaaaaaaa.', 7000),
        ];
        foreach ($inputs as $i => $s) {
            $start = microtime(true);
            [$masked] = Redactor::default()->mask($s);
            $ms = (microtime(true) - $start) * 1000;
            self::assertLessThan(500, $ms, 'input ' . $i);
            self::assertSame(PREG_NO_ERROR, preg_last_error(), 'input ' . $i);
            // Not masked whole, as when a pattern fails.
            self::assertDoesNotMatchRegularExpression('/^\[REDACTED:[a-z_]+\]$/', $masked, 'input ' . $i);
        }
        // Many findings in one text.
        $start = microtime(true);
        [$masked, $findings] = Redactor::default()->mask(str_repeat('ada@example.com ', 6000));
        self::assertLessThan(500, (microtime(true) - $start) * 1000);
        self::assertCount(6000, $findings);
        self::assertSame(str_repeat('[REDACTED:email] ', 6000), $masked);
    }

    public function testSensitiveKeys(): void
    {
        self::assertWalk(
            [
                'Auth' => '[Filtered]', 'auth' => '[Filtered]', 'author' => 'c', 'X-CSRF-Token' => '[Filtered]',
                'max_tokens' => 5, 'input_tokens' => 'x', 'token_count' => 'y', 'usage_token' => 'z',
                'empty_token' => '', 'none_token' => null, 'db_password' => '[Filtered]',
            ],
            3,
            [
                'Auth' => 'b', 'auth' => 'a', 'author' => 'c', 'X-CSRF-Token' => 'q',
                'max_tokens' => 5, 'input_tokens' => 'x', 'token_count' => 'y', 'usage_token' => 'z',
                'empty_token' => '', 'none_token' => null, 'db_password' => '[Filtered]',
            ],
        );
    }

    public function testCustomKeysReplaceTheDefaults(): void
    {
        $r = Redactor::create(['X-Api_Key']);
        $count = 0;
        $out = $r->walk(['x api key' => 's', 'apikey' => 't', 'password' => 'u', 'auth' => 'v'], $count);
        self::assertSame(['x api key' => '[Filtered]', 'apikey' => 't', 'password' => 'u', 'auth' => '[Filtered]'], $out);
        self::assertSame(2, $count);

        $count = 0;
        self::assertSame(['password' => 'u'], Redactor::create([])->walk(['password' => 'u'], $count));
        self::assertSame(0, $count);
    }

    public function testRenamedKeysAreNumberedInCodePointOrder(): void
    {
        // U+FFFF sorts before U+1F600 by code point (not in UTF-16).
        self::assertWalk(
            [
                'http://u:[REDACTED:url_credentials]@h' => '[Filtered]',
                'http://u:[REDACTED:url_credentials]@h (3)' => 2,
                'http://u:[REDACTED:url_credentials]@h (2)' => 1,
            ],
            3,
            [
                'http://u:[REDACTED:url_credentials]@h' => 3,
                "http://u:\u{1F600}x@h" => 2,
                "http://u:\u{FFFF}x@h" => 1,
            ],
        );
        // Integer keys hold data too.
        self::assertWalk(['[REDACTED:credit_card]' => 'card as key'], 1, [4111111111111111 => 'card as key']);
    }

    public function testTypedAttributesAndPairs(): void
    {
        self::assertWalk(
            [
                'password' => ['type' => 'string', 'value' => '[Filtered]'],
                'token' => ['type' => 'string', 'value' => '[Filtered]'],
                'secret' => '[Filtered]',
                'headers' => [['Authorization', '[Filtered]'], ['Cookie', ''], ['Accept', '[REDACTED:email]']],
                'pair' => ['password', '[Filtered]'],
                'map' => ['a' => 'password', 'b' => 'x'],
            ],
            5,
            [
                'password' => ['type' => 'int', 'value' => 5],
                'token' => ['type' => 'string', 'value' => '[Filtered]'],
                'secret' => ['type' => 'x', 'value' => null],
                'headers' => [['Authorization', 'Bearer abc'], ['Cookie', ''], ['Accept', 'a@b.co']],
                'pair' => ['password', '[Filtered]'],
                'map' => ['a' => 'password', 'b' => 'x'],
            ],
        );
    }

    public function testObjectsAreCopiedNotChanged(): void
    {
        $typed = new \stdClass();
        $typed->type = 'int';
        $typed->value = 5;
        $in = new \stdClass();
        $in->{'ada@example.com'} = 1;
        $in->password = $typed;
        $in->{'7'} = 'kept';
        $in->clean = new \stdClass();
        $count = 0;
        $out = Redactor::default()->walk($in, $count);
        self::assertSame(2, $count);
        self::assertInstanceOf(\stdClass::class, $out);
        self::assertSame(
            '{"[REDACTED:email]":1,"password":{"type":"string","value":"[Filtered]"},"7":"kept","clean":{}}',
            json_encode($out, JSON_UNESCAPED_SLASHES),
        );
        self::assertSame(
            '{"ada@example.com":1,"password":{"type":"int","value":5},"7":"kept","clean":{}}',
            json_encode($in, JSON_UNESCAPED_SLASHES),
        );
        self::assertSame($in->clean, $out->clean);

        $count = 0;
        self::assertSame($in->clean, Redactor::default()->walk($in->clean, $count));
    }

    public function testReferencesAreNotWrittenThrough(): void
    {
        $email = 'ada@example.com';
        $secret = 'hunter2';
        $value = 5;
        $typed = new \stdClass();
        $typed->type = 'int';
        $typed->value = &$value;
        $in = ['list' => [&$email], 'password' => &$secret, 'token' => ['type' => 'int', 'value' => &$value], 'secret' => $typed];
        $count = 0;
        $out = Redactor::default()->walk($in, $count);
        self::assertSame(4, $count);
        self::assertSame('ada@example.com', $email);
        self::assertSame('hunter2', $secret);
        self::assertSame(5, $value);
        self::assertSame(
            '{"list":["[REDACTED:email]"],"password":"[Filtered]","token":{"type":"string","value":"[Filtered]"},"secret":{"type":"string","value":"[Filtered]"}}',
            json_encode($out),
        );
    }

    public function testOtherValuesStay(): void
    {
        $object = new \ArrayObject(['email' => 'ada@example.com']);
        $in = [1, 2.5, true, false, null, $object, 'ada@example.com'];
        $count = 0;
        $out = Redactor::default()->walk($in, $count);
        self::assertSame([1, 2.5, true, false, null, $object, '[REDACTED:email]'], $out);
        self::assertSame(1, $count);
    }

    public function testContainersDeeperThanJsonEncodeAreLeftAlone(): void
    {
        $deep = 'ada@example.com';
        for ($i = 0; $i < 600; $i++) {
            $deep = [$deep];
        }
        $count = 0;
        self::assertSame($deep, Redactor::default()->walk($deep, $count));
        self::assertSame(0, $count);

        $shallow = 'ada@example.com';
        for ($i = 0; $i < 500; $i++) {
            $shallow = [$shallow];
        }
        $out = Redactor::default()->walk($shallow, $count);
        self::assertSame(1, $count);
        self::assertStringContainsString('[REDACTED:email]', (string) json_encode($out, 0, 1000));
    }

    /** @param list<string> $findings */
    private static function assertMask(string $masked, array $findings, string $input): void
    {
        self::assertSame([$masked, $findings], Redactor::default()->mask($input));
    }

    private static function assertWalk(mixed $expected, int $count, mixed $input): void
    {
        $n = 0;
        $out = Redactor::default()->walk($input, $n);
        self::assertSame($expected, $out);
        self::assertSame($count, $n);
    }
}
