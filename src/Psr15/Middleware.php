<?php

declare(strict_types=1);

namespace Fixwire\Psr15;

use Fixwire\Hub;
use Fixwire\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tracks requests in PSR-15 apps (Slim, Mezzio, …): a server span named after the route, the
 * request's details on events, and exceptions that escape the handler, sent as unhandled and
 * thrown on. Under PHP-FPM it takes over the request init() tracks.
 *
 *     $app->add(new Fixwire\Psr15\Middleware(
 *         route: fn ($request) => Slim\Routing\RouteContext::fromRequest($request)->getRoute()?->getPattern(),
 *     ));
 *
 * The route is read from the request this middleware is handed; for it to be there, add this
 * middleware where routing has happened (in Slim, before addRoutingMiddleware).
 */
final class Middleware implements MiddlewareInterface
{
    /**
     * @param (\Closure(ServerRequestInterface): ?string)|null $route reads the route the request
     *                                                                matched, such as /items/{id}
     */
    public function __construct(private ?\Closure $route = null) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $hub = Hub::current();

        return $hub->withScope(function () use ($hub, $request, $handler): ResponseInterface {
            $tracked = ServerRequest::global();
            if ($tracked === null) {
                $headers = [];
                foreach ($request->getHeaders() as $name => $values) {
                    $headers[(string) $name] = implode(', ', $values);
                }
                $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;
                $tracked = ServerRequest::start($hub, $request->getMethod(), (string) $request->getUri(), $headers, \is_string($address) ? $address : null);
            }
            $this->name($tracked, $request); // known already when routing ran before this
            try {
                $response = $handler->handle($request);
            } catch (\Throwable $e) {
                $hub->captureException($e, 'psr15', false);
                $tracked->end(500);

                throw $e;
            }
            $tracked->end($response->getStatusCode());

            return $response;
        });
    }

    private function name(ServerRequest $tracked, ServerRequestInterface $request): void
    {
        if ($this->route === null) {
            return;
        }
        try {
            $tracked->setRoute(($this->route)($request));
        } catch (\Throwable) {
            // no route, then
        }
    }
}
