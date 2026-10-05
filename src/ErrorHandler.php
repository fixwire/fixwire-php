<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * @internal reports what nothing caught: uncaught exceptions and fatal errors (as crashes), and PHP
 * warnings and notices (as breadcrumbs). The handlers that were there before still run, and PHP's
 * own output and exit code stay as they were.
 */
final class ErrorHandler
{
    private const FATAL = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR;

    private static bool $installed = false;

    /** @var callable|null */
    private static $previousException = null;

    /** @var callable|null */
    private static $previousError = null;

    private static bool $crashed = false;

    /** Kept free for a fatal error that ran out of memory (set, then let go). */
    private static ?string $reserve = null; // @phpstan-ignore property.onlyWritten

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        self::$installed = true;
        self::$reserve = str_repeat(' ', 32 * 1024);
        self::$previousException = set_exception_handler([self::class, 'onException']);
        self::$previousError = set_error_handler([self::class, 'onError']);
        register_shutdown_function([self::class, 'onShutdown']);
    }

    /** @internal whether the script is ending with an uncaught exception or a fatal error */
    public static function crashed(): bool
    {
        if (self::$crashed) {
            return true;
        }
        $error = error_get_last();

        return $error !== null && ($error['type'] & self::FATAL) !== 0;
    }

    public static function onException(\Throwable $e): void
    {
        self::$crashed = true;
        $hub = Hub::current();
        $hub->captureException($e, 'uncaught', false, Level::Fatal);
        $hub->flush();
        if (self::$previousException !== null) {
            (self::$previousException)($e);

            return;
        }
        // As if nothing had caught it: PHP prints it and exits with 255. (Restoring the handler
        // first would make PHP 8.5 call it again, forever.)
        throw $e;
    }

    public static function onError(int $type, string $message, string $file = '', int $line = 0): bool
    {
        $client = Hub::current()->getClient();
        if ($client !== null && ($type & error_reporting() & $client->options()->errorTypes) !== 0) {
            Hub::current()->addBreadcrumb(new Breadcrumb(
                category: 'php',
                message: self::name($type) . ': ' . $message,
                level: match (true) {
                    ($type & (\E_USER_ERROR | \E_RECOVERABLE_ERROR)) !== 0 => Level::Error,
                    ($type & (\E_WARNING | \E_USER_WARNING | \E_CORE_WARNING | \E_COMPILE_WARNING)) !== 0 => Level::Warning,
                    default => Level::Info,
                },
                type: 'error',
                data: ['file' => $file, 'line' => $line],
            ));
        }

        return self::$previousError !== null && (self::$previousError)($type, $message, $file, $line) !== false;
    }

    public static function onShutdown(): void
    {
        self::$reserve = null;
        $error = error_get_last();
        $hub = Hub::current();
        $client = $hub->getClient();
        // An uncaught exception ends as a fatal error too; it was sent already.
        if (!self::$crashed && $error !== null && ($error['type'] & self::FATAL) !== 0 && $client !== null && $client->isEnabled()) {
            if (str_starts_with($error['message'], 'Allowed memory size')) {
                // What ran out of memory is still held: room to build and send the event.
                @ini_set('memory_limit', (string) (memory_get_usage() + 8 * 1024 * 1024));
            }
            $frame = Frames::at(null, null, $error['file'], $error['line'], $client->options());
            $e = new Event();
            $e->level = Level::Fatal;
            $e->exceptions = [new ExceptionValue(
                type: self::name($error['type']),
                message: $error['message'],
                mechanism: 'fatal_error',
                handled: false,
                frames: [$frame],
            )];
            $hub->captureEvent($e);
        }
        // Last of all, after the app's own shutdown functions: send.
        register_shutdown_function(static function (): void {
            Hub::current()->flush();
        });
    }

    private static function name(int $type): string
    {
        return match ($type) {
            \E_ERROR => 'E_ERROR',
            \E_WARNING => 'E_WARNING',
            \E_PARSE => 'E_PARSE',
            \E_NOTICE => 'E_NOTICE',
            \E_CORE_ERROR => 'E_CORE_ERROR',
            \E_CORE_WARNING => 'E_CORE_WARNING',
            \E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            \E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            \E_USER_ERROR => 'E_USER_ERROR',
            \E_USER_WARNING => 'E_USER_WARNING',
            \E_USER_NOTICE => 'E_USER_NOTICE',
            \E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            \E_DEPRECATED => 'E_DEPRECATED',
            \E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_' . $type,
        };
    }
}
