<?php

// A fake Fixwire for `php -S`: appends each request (path, headers, decoded body) to the file in
// FAKE_INGEST_LOG as a JSON line.

declare(strict_types=1);

$raw = (string) file_get_contents('php://input');
if (($_SERVER['HTTP_CONTENT_ENCODING'] ?? '') === 'gzip') {
    $raw = (string) gzdecode($raw);
}
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
    }
}
$path = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], \PHP_URL_PATH));
file_put_contents(
    (string) getenv('FAKE_INGEST_LOG'),
    json_encode(['path' => $path, 'headers' => $headers, 'body' => json_decode($raw, true)]) . "\n",
    \FILE_APPEND | \LOCK_EX,
);
header('Content-Type: application/json');
echo '{}';
