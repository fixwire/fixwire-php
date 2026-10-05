<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * An outgoing HTTP request, for HTTP client integrations: a client span under the current span when
 * it is sampled, trace headers when the URL is one of the trace propagation targets, and an http
 * breadcrumb when it ends. Nothing here throws into the caller's request.
 */
final class OutgoingRequest
{
    private bool $ended = false;

    private function __construct(
        private Hub $hub,
        private string $method,
        private string $url,
        private ?Span $span,
    ) {}

    /**
     * Starts tracking a request; $setHeader adds a trace header to it before it is sent.
     *
     * @param callable(string, string): void $setHeader
     */
    public static function start(Hub $hub, string $method, string $url, callable $setHeader): self
    {
        $plain = (string) preg_replace('/[?#].*$/s', '', $url);
        $method = strtoupper($method);
        $span = null;
        try {
            $parent = $hub->getSpan();
            if ($parent !== null && $parent->sampled) {
                $span = Span::start($hub, $method . ' ' . $plain, 'http.client', [
                    'http.request.method' => $method,
                    'url.full' => $plain,
                    'server.address' => parse_url($url, \PHP_URL_HOST) ?: null,
                ], SpanKind::Client, current: false);
            }
            $from = $span ?? $parent;
            if ($from !== null && $hub->getClient()?->shouldPropagate($url) === true) {
                $setHeader('traceparent', $from->traceparent());
                if ($from->tracestate !== null) {
                    $setHeader('tracestate', $from->tracestate);
                }
                if ($from->baggage !== null) {
                    $setHeader('baggage', $from->baggage);
                }
            }
        } catch (\Throwable) {
            // tracing must never break the request
        }

        return new self($hub, $method, $plain, $span);
    }

    /** Ends the request with the server's answer; a 4xx or 5xx fails the span. */
    public function end(int $status): void
    {
        $this->finish($status, null);
    }

    /** Ends a request that got no answer. */
    public function fail(\Throwable $error): void
    {
        $this->finish(0, $error);
    }

    private function finish(int $status, ?\Throwable $error): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        try {
            if ($this->span !== null) {
                if ($status > 0) {
                    $this->span->setAttribute('http.response.status_code', $status);
                }
                if ($error !== null) {
                    $this->span->setError($error);
                } elseif ($status >= 400) {
                    $this->span->setError('HTTP ' . $status);
                }
                $this->span->finish();
            }
            $data = ['method' => $this->method, 'url' => $this->url];
            if ($status > 0) {
                $data['status_code'] = $status;
            }
            $this->hub->addBreadcrumb(new Breadcrumb(
                category: 'http',
                level: $error !== null || $status >= 500 ? Level::Error : Level::Info,
                type: 'http',
                data: $data,
            ));
        } catch (\Throwable) {
            // tracing must never break the request
        }
    }
}
