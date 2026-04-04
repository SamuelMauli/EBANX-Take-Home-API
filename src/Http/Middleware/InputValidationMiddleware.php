<?php

declare(strict_types=1);

namespace Ebanx\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

final class InputValidationMiddleware implements MiddlewareInterface
{
    private const int MAX_PAYLOAD_BYTES = 1024;
    private const array ALLOWED_FIELDS = ['type', 'destination', 'origin', 'amount'];
    private const int MAX_AMOUNT = 1_000_000_000;

    public function process(Request $request, RequestHandler $handler): Response
    {
        if ($request->getMethod() === 'POST') {
            $bodySize = $request->getBody()->getSize();
            if ($bodySize !== null && $bodySize > self::MAX_PAYLOAD_BYTES) {
                $response = new SlimResponse();
                $response->getBody()->write('Payload too large');

                return $response->withStatus(413);
            }

            $body = $request->getParsedBody();
            if (is_array($body) && !empty($body)) {
                $sanitized = array_intersect_key($body, array_flip(self::ALLOWED_FIELDS));

                // Trim account IDs to prevent " 100 " vs "100" inconsistency
                foreach (['origin', 'destination'] as $field) {
                    if (isset($sanitized[$field])) {
                        $sanitized[$field] = trim((string) $sanitized[$field]);
                    }
                }

                // Validate amount is numeric, integer, and within safe bounds
                if (isset($sanitized['amount'])) {
                    if (!is_numeric($sanitized['amount'])) {
                        return $this->badRequest('Amount must be numeric');
                    }

                    if (is_float($sanitized['amount'])) {
                        return $this->badRequest('Amount must be a whole number');
                    }

                    $amount = (int) $sanitized['amount'];
                    if ($amount > self::MAX_AMOUNT) {
                        return $this->badRequest('Amount exceeds maximum allowed value');
                    }

                    $sanitized['amount'] = $amount;
                }

                // Validate required fields based on event type
                if (isset($sanitized['type'])) {
                    $requiredFields = match ($sanitized['type']) {
                        'deposit' => ['destination', 'amount'],
                        'withdraw' => ['origin', 'amount'],
                        'transfer' => ['origin', 'destination', 'amount'],
                        default => [],
                    };

                    foreach ($requiredFields as $field) {
                        if (!isset($sanitized[$field])) {
                            return $this->badRequest(sprintf('Missing required field: %s', $field));
                        }
                    }

                    // Self-transfer check
                    if ($sanitized['type'] === 'transfer'
                        && isset($sanitized['origin'], $sanitized['destination'])
                        && (string) $sanitized['origin'] === (string) $sanitized['destination']
                    ) {
                        return $this->badRequest('Origin and destination must be different');
                    }
                }

                // Validate account IDs are non-empty (already trimmed above)
                foreach (['origin', 'destination'] as $field) {
                    if (isset($sanitized[$field]) && $sanitized[$field] === '') {
                        return $this->badRequest(sprintf('%s cannot be empty', ucfirst($field)));
                    }
                }

                $request = $request->withParsedBody($sanitized);
            }
        }

        return $handler->handle($request);
    }

    private function badRequest(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write($message);

        return $response->withStatus(400);
    }
}
