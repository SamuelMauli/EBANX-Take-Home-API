<?php

declare(strict_types=1);

namespace Ebanx\Http;

use Ebanx\Domain\AccountService;
use Ebanx\Domain\Exception\InvalidEventTypeException;
use Ebanx\Http\Response\EventResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AccountController
{
    public function __construct(
        private readonly AccountService $service,
    ) {
    }

    public function reset(Request $request, Response $response): Response
    {
        $this->service->reset();

        return ResponseFactory::plain($response, 'OK');
    }

    public function balance(Request $request, Response $response): Response
    {
        $accountId = $request->getQueryParams()['account_id'] ?? '';

        $balance = $this->service->getBalance((string) $accountId);

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
