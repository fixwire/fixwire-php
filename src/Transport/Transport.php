<?php

declare(strict_types=1);

namespace Fixwire\Transport;

/**
 * Sends a request to Fixwire. The SDK queues what it captures and sends it in one go when the
 * client flushes (at the end of the request, or when a worker asks).
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: array<string, string>} the status (0 when there was no answer) and the answer's headers, names in lower case; with no answer, an "error" entry may say why
     */
    public function send(string $url, string $body, array $headers): array;
}
