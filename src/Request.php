<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * The HTTP request an event happened in.
 */
final class Request
{
    /** @var (\Closure(): ?string)|null Reads the route when an event needs it (frameworks match it later). */
    public ?\Closure $routeProvider = null;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public ?string $method = null,
        /** The URL without its query. */
        public ?string $url = null,
        public ?string $query = null,
        public array $headers = [],
        /** The route the request matched, such as /items/{id}. */
        public ?string $route = null,
        /** Sent only with the send_default_pii option. */
        public ?string $clientAddress = null,
    ) {}

    public function currentRoute(): ?string
    {
        if ($this->route !== null || $this->routeProvider === null) {
            return $this->route;
        }
        try {
            return ($this->routeProvider)();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether a header may identify someone or hold a secret; such headers are sent only with the
     * send_default_pii option.
     */
    public static function isSensitiveHeader(string $name): bool
    {
        return \in_array(strtolower($name), [
            'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-forwarded-for', 'x-real-ip', 'x-api-key',
        ], true);
    }
}
