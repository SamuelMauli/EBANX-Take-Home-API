<?php

declare(strict_types=1);

namespace Ebanx\Infrastructure;

use Ebanx\Domain\Account;
use Ebanx\Domain\AccountRepositoryInterface;

final class InMemoryAccountRepository implements AccountRepositoryInterface
{
    /** @var array<string, Account> */
    private array $accounts = [];

    public function find(string $id): ?Account
    {
        return $this->accounts[$id] ?? null;
    }

    public function save(Account $account): void
    {
        $this->accounts[$account->getId()] = $account;
    }

    public function clear(): void
    {
        $this->accounts = [];
    }

    public function atomic(callable $callback): mixed
    {
        // In-memory is single-process — no concurrency, just execute
        return $callback();
    }
}
