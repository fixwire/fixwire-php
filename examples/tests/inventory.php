<?php

// A fake inventory service for `php -S`: holds every item, and appends the trace headers it got
// to the file in INVENTORY_LOG.

declare(strict_types=1);

file_put_contents((string) getenv('INVENTORY_LOG'), json_encode([
    'path' => (string) $_SERVER['REQUEST_URI'],
    'traceparent' => $_SERVER['HTTP_TRACEPARENT'] ?? null,
]) . "\n", \FILE_APPEND | \LOCK_EX);
http_response_code(201);
header('Content-Type: application/json');
echo '{"held":1}';
