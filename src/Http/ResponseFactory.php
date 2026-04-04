<?php

declare(strict_types=1);

namespace Ebanx\Http;

use Psr\Http\Message\ResponseInterface as Response;

final class ResponseFactory
{
    public static function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        );

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    public static function plain(Response $response, string $body, int $status = 200): Response
    {
        $response->getBody()->write($body);

        return $response
            ->withHeader('Content-Type', 'text/plain')
            ->withStatus($status);
    }

    public static function error(Response $response, string $code, string $message, int $status): Response
    {
        return self::json($response, [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
