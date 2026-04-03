<?php

declare(strict_types=1);

namespace Ebanx\Domain;

interface AccountRepositoryInterface
{
    public function find(string $id): ?Account;

    public function save(Account $account): void;

    public function clear(): void;
}
