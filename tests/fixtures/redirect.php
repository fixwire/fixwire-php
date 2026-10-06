<?php

// A Fixwire that moved, for `php -S`: answers every request with a redirect to REDIRECT_TO.

declare(strict_types=1);

header('Location: ' . getenv('REDIRECT_TO'), true, 307);
