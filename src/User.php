<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * Who the work is for.
 */
final class User
{
    public function __construct(
        public ?string $id = null,
        public ?string $email = null,
        public ?string $username = null,
        /** Sent only with the send_default_pii option. */
        public ?string $ipAddress = null,
    ) {}
}
