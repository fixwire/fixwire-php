<?php

declare(strict_types=1);

namespace Fixwire\Guzzle;

use Fixwire\Hub;
use Fixwire\OutgoingRequest;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Times Guzzle requests as client spans of the current trace, sends trace headers to the trace
 * propagation targets, and leaves an http breadcrumb for each.
 *
 *     $stack = GuzzleHttp\HandlerStack::create();
 *     $stack->push(Fixwire\Guzzle\Middleware::trace());
 *     $client = new GuzzleHttp\Client(['handler' => $stack]);
 */
final class Middleware
{
    /** @return callable(callable): callable */
    public static function trace(): callable
    {
        return static fn(callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $traced = $request;
            $outgoing = OutgoingRequest::start(
                Hub::current(),
                $request->getMethod(),
                (string) $request->getUri(),
                static function (string $name, string $value) use ($request, &$traced): void {
                    if (!$request->hasHeader($name)) {
                        $traced = $traced->withHeader($name, $value); // the app's own trace headers win
                    }
                },
            );
            /** @var PromiseInterface $promise */
            $promise = $handler($traced, $options);

            return $promise->then(
                static function (ResponseInterface $response) use ($outgoing): ResponseInterface {
                    $outgoing->end($response->getStatusCode());

                    return $response;
                },
                static function ($reason) use ($outgoing) {
                    if ($reason instanceof \Throwable) {
                        $outgoing->fail($reason);
                    }

                    return \GuzzleHttp\Promise\Create::rejectionFor($reason);
                },
            );
        };
    }
}
