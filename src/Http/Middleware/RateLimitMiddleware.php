<?php

declare(strict_types=1);

namespace Ebanx\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

final class RateLimitMiddleware implements MiddlewareInterface
{
    private const int MAX_REQUESTS = 100;
    private const int WINDOW_SECONDS = 60;

    /** @var array<string, list<float>> */
    private array $requests = [];

    public function process(Request $request, RequestHandler $handler): Response
    {
        $clientIp = $this->getClientIp($request);
        $now = microtime(true);

        $this->cleanExpired($clientIp, $now);

        if ($this->isLimited($clientIp)) {
            $response = new SlimResponse();
            $response->getBody()->write('Rate limit exceeded');

            return $response
                ->withStatus(429)
                ->withHeader('Retry-After', (string) self::WINDOW_SECONDS)
                ->withHeader('X-RateLimit-Limit', (string) self::MAX_REQUESTS)
                ->withHeader('X-RateLimit-Remaining', '0');
        }

        $this->requests[$clientIp][] = $now;

        $response = $handler->handle($request);
        $remaining = self::MAX_REQUESTS - count($this->requests[$clientIp] ?? []);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) self::MAX_REQUESTS)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $remaining));
    }

    private function getClientIp(Request $request): string
    {
        // Only trust REMOTE_ADDR — X-Forwarded-For is client-spoofable
        // and would allow attackers to bypass rate limiting entirely
        return $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private function cleanExpired(string $ip, float $now): void
    {
        if (!isset($this->requests[$ip])) {
            return;
        }

        $cutoff = $now - self::WINDOW_SECONDS;
        $this->requests[$ip] = array_values(
            array_filter(
                $this->requests[$ip],
                static fn(float $timestamp): bool => $timestamp > $cutoff,
            )
        );
    }

    private function isLimited(string $ip): bool
    {
        return count($this->requests[$ip] ?? []) >= self::MAX_REQUESTS;
    }
}
