<?php

declare(strict_types=1);

namespace Ebanx\Http\Middleware;

use Ebanx\Infrastructure\IdempotencyStore;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Idempotency middleware for POST /event.
 *
 * If the client sends an Idempotency-Key header and the same key was already
 * processed, the stored response is replayed without re-executing the operation.
 * This prevents duplicate deposits/withdrawals/transfers on network retries.
 */
final class IdempotencyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly IdempotencyStore $store,
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $key = $request->getHeaderLine('Idempotency-Key');

        // No key provided — proceed normally
        if ($key === '') {
            return $handler->handle($request);
        }

        // Only apply to POST (state-changing operations)
        if ($request->getMethod() !== 'POST') {
            return $handler->handle($request);
        }

        // Check for cached response
        $cached = $this->store->get($key);
        if ($cached !== null) {
            $response = new SlimResponse();
            $response->getBody()->write($cached['body']);

            return $response
                ->withStatus($cached['status'])
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Idempotency-Replayed', 'true');
        }

        // Execute the request
        $response = $handler->handle($request);

        // Store the response for future replays (only for successful operations)
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            $body = (string) $response->getBody();
            $this->store->set($key, $status, $body);

            // Rewind body so downstream can read it
            $response->getBody()->rewind();
        }

        return $response;
    }
}
