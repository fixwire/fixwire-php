<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * @internal sends what was captured at the end of the request or script, after the app's own
 * shutdown functions. Under PHP-FPM and LiteSpeed the response is ended first, as Symfony and
 * Laravel end theirs before they send: the client doesn't wait for Fixwire.
 */
final class FlushAtExit
{
    private static bool $installed = false;

    /** Whether the SDK ended the response. */
    private static bool $ended = false;

    /** Sends at exit (used when the error handlers, which do it too, are not installed). */
    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        self::$installed = true;
        register_shutdown_function(static function (): void {
            // Registered from a shutdown function: after the app's own, whenever it registered them.
            register_shutdown_function([self::class, 'flush']);
        });
    }

    /** Sends what was captured; ends the response first where PHP can (see ender()). */
    public static function flush(): void
    {
        $hub = Hub::current();
        $client = $hub->getClient();
        $end = $client === null || self::$ended ? null : self::ender(\PHP_SAPI, $client->options()->finishRequest, $client->hasQueued());
        if ($client !== null && $end !== null) {
            self::$ended = true;
            try {
                // As frameworks do before they end the response: the session is written as the app
                // left it, and its lock let go, so that the user's next request doesn't wait for this.
                if (session_status() === \PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                $end(); // sends what the output buffers hold, then ends the response
            } catch (\Throwable $e) {
                $client->log('ending the response failed: ' . $e->getMessage());
            }
        }
        $hub->flush();
    }

    /**
     * The function that ends the response before sending at exit: PHP-FPM's
     * fastcgi_finish_request(), LiteSpeed's litespeed_finish_request(). None in the CLI and other
     * SAPIs, with the finish_request option off, or with nothing to send (a framework that ended
     * the response sent what it had then). On a response ended already, the function does nothing.
     *
     * @param (\Closure(string): bool)|null $exists whether a function exists (tests)
     *
     * @return callable-string|null
     */
    public static function ender(string $sapi, bool $on, bool $queued, ?\Closure $exists = null): ?string
    {
        $function = match ($sapi) {
            'fpm-fcgi' => 'fastcgi_finish_request',
            'litespeed' => 'litespeed_finish_request',
            default => null,
        };
        if ($function === null || !$on || !$queued) {
            return null;
        }

        return ($exists === null ? \function_exists($function) : $exists($function)) ? $function : null;
    }
}
