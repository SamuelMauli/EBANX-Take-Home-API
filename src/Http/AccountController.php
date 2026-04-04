<?php

declare(strict_types=1);

namespace Ebanx\Http;

use Ebanx\Domain\AccountService;
use Ebanx\Domain\Exception\InvalidEventTypeException;
use Ebanx\Http\Response\EventResponse;
use Ebanx\Infrastructure\IdempotencyStore;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AccountController
{
    public function __construct(
        private readonly AccountService $service,
        private readonly ?IdempotencyStore $idempotencyStore = null,
    ) {
    }

    public function reset(Request $request, Response $response): Response
    {
        $this->service->reset();
        $this->idempotencyStore?->clear();

        return ResponseFactory::plain($response, 'OK');
    }

    public function balance(Request $request, Response $response): Response
    {
        $accountId = trim($request->getQueryParams()['account_id'] ?? '');

        if ($accountId === '') {
            return ResponseFactory::plain($response, '0', 400);
        }

        $balance = $this->service->getBalance($accountId);

        return ResponseFactory::plain($response, (string) $balance);
    }

    public function event(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $type = $body['type'] ?? '';

        return match ($type) {
            'deposit' => $this->handleDeposit($body, $response),
            'withdraw' => $this->handleWithdraw($body, $response),
            'transfer' => $this->handleTransfer($body, $response),
            default => throw InvalidEventTypeException::unknown($type),
        };
    }

    public function health(Request $request, Response $response): Response
    {
        return ResponseFactory::json($response, [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'php_version' => PHP_VERSION,
        ]);
    }

    private function handleDeposit(array $body, Response $response): Response
    {
        $account = $this->service->deposit(
            (string) $body['destination'],
            (int) $body['amount'],
        );

        return ResponseFactory::json($response, EventResponse::deposit($account), 201);
    }

    private function handleWithdraw(array $body, Response $response): Response
    {
        $account = $this->service->withdraw(
            (string) $body['origin'],
            (int) $body['amount'],
        );

        return ResponseFactory::json($response, EventResponse::withdraw($account), 201);
    }

    private function handleTransfer(array $body, Response $response): Response
    {
        $result = $this->service->transfer(
            (string) $body['origin'],
            (string) $body['destination'],
            (int) $body['amount'],
        );

        return ResponseFactory::json(
            $response,
            EventResponse::transfer($result['origin'], $result['destination']),
            201,
        );
    }
}
