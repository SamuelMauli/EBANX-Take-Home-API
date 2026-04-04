<?php

declare(strict_types=1);

namespace Ebanx\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Adds a unique X-Request-ID to every response for distributed tracing.
 * If the client sends one, it is echoed back; otherwise a new UUID is generated.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $requestId = $request->getHeaderLine('X-Request-ID');

        if ($requestId === '') {
            $requestId = self::uuid4();
        }

        $request = $request->withAttribute('request_id', $requestId);

        $response = $handler->handle($request);

        return $response->withHeader('X-Request-ID', $requestId);
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40); // version 4
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80); // variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
