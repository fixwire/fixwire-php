<?php

// A page served by `php -S` in ProcessTest (FIXWIRE_DSN set): init() tracks the request.

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

Fixwire\init([
    'release' => 'shop@1.0.0',
    'project_root' => \dirname(__DIR__, 2),
    'traces_sample_rate' => 1.0,
    'auto_session_tracking' => true,
]);

if (str_starts_with((string) $_SERVER['REQUEST_URI'], '/boom')) {
    throw new RuntimeException('the oven is on fire');
}
Fixwire\trace(static fn() => usleep(1000), 'SELECT orders', 'db.query');
Fixwire\captureMessage('order page served');
http_response_code(201);
echo 'ok';
