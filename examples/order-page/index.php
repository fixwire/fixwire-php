<?php

// A page of a PHP app without a framework, under PHP-FPM or Apache's mod_php:
// Fixwire\init() at the top is all it takes. The request becomes a trace that
// continues the caller's, PHP warnings become breadcrumbs, and an exception
// nothing caught or a fatal error is reported as a crash.
//
//   FIXWIRE_DSN=https://<key>@<host> php -S localhost:8080 -t order-page

declare(strict_types=1);

require \dirname(__DIR__) . '/vendor/autoload.php';

// The DSN comes from FIXWIRE_DSN; without it, Fixwire does nothing.
Fixwire\init(['release' => getenv('RELEASE') ?: 'order-page@1.0.0', 'traces_sample_rate' => 1.0]);

// The signed-in user, from the app's own session (here: a cookie).
if (isset($_COOKIE['user_id']) && \is_string($_COOKIE['user_id'])) {
    Fixwire\setUser(new Fixwire\User($_COOKIE['user_id']));
}

// The orders stand in for a database; 1002 was saved by an old version, without its items.
$orders = [
    '1001' => ['id' => '1001', 'customer' => 'Ada', 'items' => ['Mug', 'Poster']],
    '1002' => ['id' => '1002', 'customer' => 'Grace'],
];

$id = \is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$order = Fixwire\trace(static fn() => $orders[$id] ?? null, 'SELECT orders', 'db.query', ['db.system' => 'mysql']);
if ($order === null) {
    http_response_code(404); // a 404 is not an error worth reporting

    echo 'No such order';

    return;
}

// A bug: an order without items is a warning (a breadcrumb), then a TypeError nothing catches.
// Fixwire reports it as a crash; PHP answers 500.
$count = \count($order['items']);

header('Content-Type: text/html; charset=utf-8');
echo '<h1>Order ', htmlspecialchars($order['id']), '</h1><p>', htmlspecialchars($order['customer']), ', ', $count, ' items</p>';
