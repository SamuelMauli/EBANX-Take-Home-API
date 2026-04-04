<?php

declare(strict_types=1);

namespace Ebanx\Domain;

use Ebanx\Domain\Exception\AccountNotFoundException;
use Ebanx\Domain\Exception\InvalidAmountException;

final class AccountService
{
    public function __construct(
        private readonly AccountRepositoryInterface $repository,
    ) {
    }

    public function getBalance(string $accountId): int
    {
        $account = $this->repository->find($accountId);

        if ($account === null) {
            throw AccountNotFoundException::withId($accountId);
        }

        return $account->getBalance();
    }

    public function deposit(string $destination, int $amount): Account
    {
        $account = $this->repository->find($destination) ?? new Account($destination);

        $account->deposit($amount);
        $this->repository->save($account);

        return $account;
    }

    public function withdraw(string $origin, int $amount): Account
    {
        $account = $this->repository->find($origin);

        if ($account === null) {
            throw AccountNotFoundException::withId($origin);
        }

        $account->withdraw($amount);
        $this->repository->save($account);

        return $account;
    }

    /**
     * @return array{origin: Account, destination: Account}
     */
    public function transfer(string $origin, string $destination, int $amount): array
    {
        if ($origin === $destination) {
            throw InvalidAmountException::selfTransfer();
        }

        $originAccount = $this->repository->find($origin);

        if ($originAccount === null) {
            throw AccountNotFoundException::withId($origin);
        }

        $destinationAccount = $this->repository->find($destination) ?? new Account($destination);

        // Withdraw first — fails fast if insufficient funds, before any state changes
        $originAccount->withdraw($amount);
        $destinationAccount->deposit($amount);

        $this->repository->save($originAccount);
        $this->repository->save($destinationAccount);

        return [
            'origin' => $originAccount,
            'destination' => $destinationAccount,
        ];
    }

    public function reset(): void
    {
        $this->repository->clear();
    }
}
