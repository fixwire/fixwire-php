<?php

// A Fixwire that doesn't answer in time, for `php -S`: each request waits 10 seconds.

declare(strict_types=1);

sleep(10);
header('Content-Type: application/json');
echo '{}';
