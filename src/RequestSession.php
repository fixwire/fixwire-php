<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * @internal the session of the request a scope serves
 */
final class RequestSession
{
    /** ok, errored or crashed */
    public string $status = 'ok';

    public function mark(bool $crashed): void
    {
        if ($crashed) {
            $this->status = 'crashed';
        } elseif ($this->status === 'ok') {
            $this->status = 'errored';
        }
    }
}
