<?php

declare(strict_types=1);

namespace Fixwire\Tests\Internal\Redaction;

use Fixwire\Internal\Redaction\Redactor;
use PHPUnit\Framework\TestCase;

/**
 * The shared corpus of the Fixwire server's redaction
 * (pkg/redact/testdata/vectors.json): the same defaults, the same masked
 * strings and findings, the same masked documents and counts.
 */
final class VectorsTest extends TestCase
{
    private static ?\stdClass $vectors = null;

    public function testDefaultsMatchTheServer(): void
    {
        $v = self::vectors();
        self::assertSame($v->detectors, Redactor::DEFAULT_DETECTORS);
        self::assertSame($v->sensitive_keys, Redactor::DEFAULT_SENSITIVE_KEYS);
    }

    public function testStrings(): void
    {
        $v = self::vectors();
        $fixtures = self::fixtures($v);
        self::assertNotEmpty($v->strings);
        foreach ($v->strings as $case) {
            [$masked, $findings] = Redactor::default()->mask(strtr($case->input, $fixtures));
            self::assertSame(strtr($case->masked, $fixtures), $masked, $case->name);
            self::assertSame($case->findings, $findings, $case->name);
        }
    }

    public function testDocuments(): void
    {
        $v = self::vectors();
        $fixtures = self::fixtures($v);
        self::assertNotEmpty($v->documents);
        foreach ($v->documents as $case) {
            $count = 0;
            $masked = Redactor::default()->walk(self::expand($case->input, $fixtures), $count);
            self::assertSame(self::canonical(self::expand($case->masked, $fixtures)), self::canonical($masked), $case->name);
            self::assertSame($case->count, $count, $case->name);
        }
    }

    private static function vectors(): \stdClass
    {
        if (self::$vectors === null) {
            $path = self::path();
            if ($path === null) {
                self::markTestSkipped('pkg/redact/testdata/vectors.json not found (set FIXWIRE_VECTORS)');
            }
            $json = file_get_contents($path);
            self::assertIsString($json);
            // Objects stay \stdClass, so an empty object is not a list.
            $v = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            self::assertInstanceOf(\stdClass::class, $v);
            self::$vectors = $v;
        }

        return self::$vectors;
    }

    /** The corpus, found by walking up from here, else at FIXWIRE_VECTORS. */
    private static function path(): ?string
    {
        for ($dir = __DIR__; ; $dir = $parent) {
            $candidate = $dir . '/pkg/redact/testdata/vectors.json';
            if (is_file($candidate)) {
                return $candidate;
            }
            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
        }
        $env = getenv('FIXWIRE_VECTORS');

        return \is_string($env) && $env !== '' && is_file($env) ? $env : null;
    }

    /**
     * Each "{{name}}" with its fixture's parts joined (the parts keep
     * secret-looking values out of the file).
     *
     * @return array<string, string>
     */
    private static function fixtures(\stdClass $v): array
    {
        $out = [];
        foreach (get_object_vars($v->fixtures) as $name => $parts) {
            $out['{{' . $name . '}}'] = implode('', $parts);
        }

        return $out;
    }

    /** @param array<string, string> $fixtures */
    private static function expand(mixed $v, array $fixtures): mixed
    {
        if (\is_string($v)) {
            return strtr($v, $fixtures);
        }
        if (\is_array($v)) {
            return array_map(static fn(mixed $x): mixed => self::expand($x, $fixtures), $v);
        }
        if ($v instanceof \stdClass) {
            $out = new \stdClass();
            foreach (get_object_vars($v) as $k => $x) {
                $out->{strtr((string) $k, $fixtures)} = self::expand($x, $fixtures);
            }

            return $out;
        }

        return $v;
    }

    /** A document as JSON with every object's keys sorted, to compare by value. */
    private static function canonical(mixed $v): string
    {
        return json_encode(self::sorted($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function sorted(mixed $v): mixed
    {
        if (\is_array($v)) {
            return array_map(static fn(mixed $x): mixed => self::sorted($x), $v);
        }
        if ($v instanceof \stdClass) {
            $fields = [];
            foreach (get_object_vars($v) as $k => $x) {
                $fields[(string) $k] = self::sorted($x);
            }
            ksort($fields, SORT_STRING);

            return (object) $fields;
        }

        return $v;
    }
}
