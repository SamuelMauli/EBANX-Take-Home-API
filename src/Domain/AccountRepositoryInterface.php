<?php

declare(strict_types=1);

namespace Ebanx\Domain;

interface AccountRepositoryInterface
{
    public function find(string $id): ?Account;

    public function save(Account $account): void;

    public function clear(): void;

    /**
     * Execute a callback atomically — all reads and writes within are
     * guaranteed to be consistent (no interleaving from other processes).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function atomic(callable $callback): mixed;
}
