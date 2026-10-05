<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * @internal sends what was captured at the end of the request or script, after the app's own
 * shutdown functions (used when the error handlers are not installed)
 */
final class FlushAtExit
{
    private static bool $installed = false;

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        self::$installed = true;
        register_shutdown_function(static function (): void {
            register_shutdown_function(static function (): void {
                Hub::current()->flush();
            });
        });
    }
}
