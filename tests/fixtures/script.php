<?php

// A script that ends badly, run by ProcessTest: php script.php <scenario> (FIXWIRE_DSN set).

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

Fixwire\init(['release' => 'shop@1.0.0', 'project_root' => \dirname(__DIR__, 2)]);
Fixwire\addBreadcrumb('script', 'started');

function chargeCard(int $amount): void
{
    throw new InvalidArgumentException("amount {$amount} exceeds the limit");
}

switch ($argv[1] ?? '') {
    case 'uncaught':
        trigger_error('card network slow', \E_USER_WARNING);
        chargeCard(500);

        break;

    case 'fatal':
        ini_set('memory_limit', '32M');
        $held = [];
        while (true) {
            $held[] = str_repeat('x', 1024 * 1024);
        }

        // no break
    case 'message':
        Fixwire\captureMessage('nightly report sent');

        exit(0);
}
