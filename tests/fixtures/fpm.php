<?php

// A page without a framework, served by php-fpm in ProcessTest (FIXWIRE_DSN and SESSION_DIR set):
// output in a buffer, a session, and a shutdown function of its own that writes to both.
// ?finish=0 turns the finish_request option off.

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

ob_start();
ini_set('session.save_path', (string) getenv('SESSION_DIR'));
session_id('fixwire' . ($_GET['finish'] ?? '1'));
session_start();

Fixwire\init([
    'release' => 'shop@1.0.0',
    'project_root' => \dirname(__DIR__, 2),
    'timeout' => 10.0,
    'finish_request' => ($_GET['finish'] ?? '1') === '1',
]);
register_shutdown_function(static function (): void {
    $_SESSION['seen'] = 'at exit';
    echo ', goodbye';
});

Fixwire\captureMessage('page served');
echo 'hello';
