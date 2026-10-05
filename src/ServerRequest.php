<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * An incoming HTTP request, for server integrations: a server span that continues the caller's
 * trace, the request's details on the events captured while it runs, and its session. init()
 * tracks the request PHP serves from $_SERVER (the track_request option); framework integrations
 * take that one over or start their own.
 */
final class ServerRequest
{
    private static ?self $global = null;

    private bool $ended = false;

    /** @param \Closure(): void $endSession */
    private function __construct(
        private Hub $hub,
        public readonly Span $span,
        public readonly Request $request,
        private \Closure $endSession,
    ) {}

    /**
     * Starts tracking a request on the hub's current scope.
     *
     * @param array<string, string> $headers
     */
    public static function start(Hub $hub, string $method, string $url, array $headers, ?string $clientAddress = null): self
    {
        $method = strtoupper($method);
        $headers = array_change_key_case($headers);
        $parts = parse_url($url);
        $parts = \is_array($parts) ? $parts : [];
        $path = $parts['path'] ?? '/';
        $request = new Request($method, (string) preg_replace('/[?#].*$/s', '', $url), $parts['query'] ?? null, $headers, null, $clientAddress);
        $hub->getScope()->setRequest($request);
        $span = $hub->continueTrace($headers['traceparent'] ?? null, $headers['tracestate'] ?? null, $headers['baggage'] ?? null, $method . ' ' . $path, 'http.server', [
            'http.request.method' => $method,
            'url.path' => $path,
            'url.scheme' => $parts['scheme'] ?? null,
            'server.address' => $parts['host'] ?? null,
            'user_agent.original' => $headers['user-agent'] ?? null,
        ]);

        return new self($hub, $span, $request, $hub->startRequestSession());
    }

    /**
     * Tracks the request PHP is serving, from $_SERVER, until PHP has answered it; null in the CLI.
     *
     * @param array<string, mixed>|null $server $_SERVER
     */
    public static function fromGlobals(Hub $hub, ?array $server = null): ?self
    {
        $server ??= $_SERVER;
        if (\in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) && $server === $_SERVER) {
            return null;
        }
        if (!isset($server['REQUEST_METHOD']) || !\is_string($server['REQUEST_METHOD'])) {
            return null;
        }
        $headers = [];
        foreach ($server as $key => $value) {
            if (!\is_string($value)) {
                continue;
            }
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headers[str_replace('_', '-', strtolower(substr((string) $key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', strtolower($key))] = $value;
            }
        }
        $https = isset($server['HTTPS']) && $server['HTTPS'] !== '' && $server['HTTPS'] !== 'off';
        $host = $headers['host'] ?? (\is_string($server['SERVER_NAME'] ?? null) ? $server['SERVER_NAME'] : 'localhost');
        $uri = \is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $address = \is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : null;
        $tracked = self::start($hub, $server['REQUEST_METHOD'], ($https ? 'https' : 'http') . '://' . $host . $uri, $headers, $address);
        self::$global = $tracked;
        register_shutdown_function(static function () use ($tracked): void {
            $status = http_response_code();
            $tracked->end(ErrorHandler::crashed() ? 500 : (\is_int($status) ? $status : 200));
        });

        return $tracked;
    }

    /**
     * @internal the request init() tracks, for a framework integration to take over (name its
     * route, end it with the response); null when there is none or it has ended
     */
    public static function global(): ?self
    {
        return self::$global !== null && !self::$global->ended ? self::$global : null;
    }

    /** Names the route the request matched, such as /items/{id}: the span's name and the events' transaction. */
    public function setRoute(?string $route): self
    {
        if ($route !== null && $route !== '') {
            $this->request->route = $route;
            $this->span->name = $this->request->method . ' ' . $route;
            $this->span->setAttribute('http.route', $route);
        }

        return $this;
    }

    /** Ends the request with its status code; a 5xx fails the span. Later calls do nothing. */
    public function end(int $status): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        try {
            $this->span->setAttribute('http.response.status_code', $status);
            if ($status >= 500) {
                $this->span->setError('HTTP ' . $status);
            }
            $this->span->finish();
            ($this->endSession)();
        } catch (\Throwable $e) {
            $this->hub->getClient()?->log('ending the request failed: ' . $e->getMessage());
        }
    }
}
