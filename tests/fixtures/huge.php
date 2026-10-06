<?php

// A Fixwire that answers at length, for `php -S`: 64 MB of body.

declare(strict_types=1);

header('Content-Type: application/json');
for ($i = 0; $i < 64; $i++) {
    echo str_repeat(' ', 1024 * 1024);
}
