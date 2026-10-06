<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * @internal throwables and their stacks, as events carry them
 */
final class Frames
{
    /** The previous throwables followed, the outermost included. */
    public const MAX_CHAIN = 10;

    /** The source files read: regular files of at most MAX_SOURCE_BYTES, MAX_SOURCES of them at a time. */
    private const MAX_SOURCE_BYTES = 10 * 1024 * 1024;
    private const MAX_SOURCES = 64;

    /** The bytes of the files the cache holds, before it starts again. */
    private const MAX_CACHED_BYTES = 32 * 1024 * 1024;

    /** @var array<string, string> file → its text */
    private static array $sources = [];

    private static int $cachedBytes = 0;

    /**
     * A throwable and its previous ones, the outermost first.
     *
     * @return list<ExceptionValue>
     */
    public static function chain(\Throwable $throwable, string $mechanism, bool $handled, Options $options): array
    {
        $chain = [];
        $seen = [];
        for ($e = $throwable; $e !== null && \count($chain) < self::MAX_CHAIN && !isset($seen[spl_object_id($e)]); $e = $e->getPrevious()) {
            $seen[spl_object_id($e)] = true;
            $type = $e::class;
            $slash = strrpos($type, '\\');
            $chain[] = new ExceptionValue(
                type: $type,
                message: $e->getMessage(),
                module: $slash === false ? '' : substr($type, 0, $slash),
                mechanism: $chain === [] ? $mechanism : 'chained',
                handled: $handled,
                frames: self::fromThrowable($e, $options),
            );
        }

        return $chain;
    }

    /**
     * A throwable's stack, the oldest call first. PHP's trace lists each call with where it was
     * made from, so a frame takes its function from one entry and its place from the one before.
     *
     * @return list<Frame>
     */
    public static function fromThrowable(\Throwable $e, Options $options): array
    {
        return self::fromTrace($e->getTrace(), $e->getFile(), $e->getLine(), $options);
    }

    /**
     * A backtrace (debug_backtrace(), the newest call first) where the newest place is $file:$line;
     * of a deeper one, the newest max_stack_frames.
     *
     * @param list<array<string, mixed>> $trace
     *
     * @return list<Frame>
     */
    public static function fromTrace(array $trace, ?string $file, int $line, Options $options): array
    {
        $max = max(1, $options->maxStackFrames);
        $frames = []; // the newest first
        foreach ($trace as $t) {
            if (\count($frames) === $max) {
                return array_reverse($frames);
            }
            $class = isset($t['class']) && \is_string($t['class']) ? $t['class'] : null;
            $function = isset($t['function']) && \is_string($t['function']) ? $t['function'] : null;
            $frames[] = self::at($class, $function, $file, $line, $options);
            $file = isset($t['file']) && \is_string($t['file']) ? $t['file'] : null;
            $line = isset($t['line']) && \is_int($t['line']) ? $t['line'] : 0;
        }
        if (\count($frames) < $max) {
            $frames[] = self::at(null, '{main}', $file, $line, $options);
        }

        return array_reverse($frames);
    }

    /** @internal one frame: its path relative to the project root, whether it is the app's, its lines */
    public static function at(?string $class, ?string $function, ?string $file, int $line, Options $options): Frame
    {
        if ($function !== null && str_contains($function, '{closure')) {
            // Before 8.4, PHP puts the namespace in front; from 8.4, the closure's place after it,
            // which moves with every edit.
            $function = '{closure}';
        }
        $f = new Frame(function: $function, module: $class, file: self::relative($file, $options), line: $line);
        $f->inApp = self::inApp($class, $file, $options);
        if ($f->inApp && $file !== null && $line > 0 && $options->contextLines > 0) {
            self::addContext($f, $file, $line, $options->contextLines);
        }

        return $f;
    }

    /**
     * The file as the app sees it: relative to the project root when it is under it, with forward
     * slashes everywhere, so an error groups alike on Windows and elsewhere.
     */
    private static function relative(?string $file, Options $options): ?string
    {
        if ($file === null) {
            return null;
        }
        $file = str_replace('\\', '/', $file);
        $root = $options->projectRoot === null ? '' : str_replace('\\', '/', $options->projectRoot);
        if ($root !== '' && str_starts_with($file, $root . '/')) {
            return substr($file, \strlen($root) + 1);
        }

        return $file;
    }

    /** Whether a frame is the app's: its class matches the options, else its file is under the project root and outside vendor/. */
    public static function inApp(?string $class, ?string $file, Options $options): bool
    {
        if ($class !== null) {
            foreach ($options->inAppExclude as $p) {
                if (str_starts_with($class, $p)) {
                    return false;
                }
            }
            foreach ($options->inAppInclude as $p) {
                if (str_starts_with($class, $p)) {
                    return true;
                }
            }
        }
        if ($file === null) {
            return false;
        }
        $file = str_replace('\\', '/', $file);
        if (str_contains($file, '/vendor/')) {
            return false;
        }
        $root = $options->projectRoot === null ? null : str_replace('\\', '/', $options->projectRoot);

        return $root === null || $root === '' || str_starts_with($file, $root . '/');
    }

    private static function addContext(Frame $f, string $file, int $line, int $around): void
    {
        $source = self::$sources[$file] ??= self::read($file);
        // Only the lines around: the file stays one string, not an array of its lines.
        $first = max(1, $line - $around);
        $pos = 0;
        for ($n = 1; $n < $first; $n++) {
            $pos = strpos($source, "\n", $pos);
            if ($pos === false) {
                return;
            }
            $pos++;
        }
        $lines = [];
        for ($n = $first; $n <= $line + $around && $pos < \strlen($source); $n++) {
            $end = strpos($source, "\n", $pos);
            $lines[$n] = mb_substr(rtrim(substr($source, $pos, $end === false ? null : $end - $pos), "\r"), 0, 300);
            if ($end === false) {
                break;
            }
            $pos = $end + 1;
        }
        if (!isset($lines[$line])) {
            return;
        }
        $f->contextLine = $lines[$line];
        $f->preContext = array_values(array_filter($lines, static fn(int $n): bool => $n < $line, \ARRAY_FILTER_USE_KEY));
        $f->postContext = array_values(array_filter($lines, static fn(int $n): bool => $n > $line, \ARRAY_FILTER_USE_KEY));
    }

    /**
     * A file's text, from a regular file (a FIFO would block, a device never end) of at most
     * MAX_SOURCE_BYTES, through a cache bounded in files and bytes; '' when it can't be read.
     */
    private static function read(string $file): string
    {
        $size = is_file($file) && is_readable($file) ? (int) @filesize($file) : -1;
        if ($size < 0 || $size > self::MAX_SOURCE_BYTES) {
            return '';
        }
        if (\count(self::$sources) >= self::MAX_SOURCES || self::$cachedBytes + $size > self::MAX_CACHED_BYTES) {
            self::$sources = [];
            self::$cachedBytes = 0;
        }
        self::$cachedBytes += $size;
        $source = @file_get_contents($file); // no length: PHP 8.1 would allocate all of it up front

        return $source === false ? '' : $source;
    }
}
