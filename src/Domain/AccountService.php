<?php

declare(strict_types=1);

namespace Ebanx\Domain;

use Ebanx\Domain\Exception\AccountNotFoundException;
use Ebanx\Domain\Exception\InvalidAmountException;
use Ebanx\Infrastructure\TransactionLog;

final class AccountService
{
    public function __construct(
        private readonly AccountRepositoryInterface $repository,
        private readonly ?TransactionLog $transactionLog = null,
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
        $balanceBefore = $account->getBalance();

        $account->deposit($amount);
        $this->repository->save($account);

        $this->log('deposit', [
            'destination' => $destination,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $account->getBalance(),
        ]);

        return $account;
    }

    public function withdraw(string $origin, int $amount): Account
    {
        $account = $this->repository->find($origin);

        if ($account === null) {
            throw AccountNotFoundException::withId($origin);
        }

        $balanceBefore = $account->getBalance();

        $account->withdraw($amount);
        $this->repository->save($account);

        $this->log('withdraw', [
            'origin' => $origin,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $account->getBalance(),
        ]);

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

        // Atomic block ensures both accounts are read and written under a single
        // lock — no other process can interleave between the two saves.
        $result = $this->repository->atomic(function () use ($origin, $destination, $amount): array {
            $originAccount = $this->repository->find($origin);

            if ($originAccount === null) {
                throw AccountNotFoundException::withId($origin);
            }

            $destinationAccount = $this->repository->find($destination) ?? new Account($destination);

            $originBefore = $originAccount->getBalance();
            $destinationBefore = $destinationAccount->getBalance();

            // Withdraw first — fails fast if insufficient funds
            $originAccount->withdraw($amount);
            $destinationAccount->deposit($amount);

            $this->repository->save($originAccount);
            $this->repository->save($destinationAccount);

            return [
                'origin' => $originAccount,
                'destination' => $destinationAccount,
                '_origin_before' => $originBefore,
                '_destination_before' => $destinationBefore,
            ];
        });

        $this->log('transfer', [
            'origin' => $origin,
            'destination' => $destination,
            'amount' => $amount,
            'origin_balance_before' => $result['_origin_before'],
            'origin_balance_after' => $result['origin']->getBalance(),
            'destination_balance_before' => $result['_destination_before'],
            'destination_balance_after' => $result['destination']->getBalance(),
        ]);

        return [
            'origin' => $result['origin'],
            'destination' => $result['destination'],
        ];
    }

    public function reset(): void
    {
        $this->repository->clear();
        $this->log('reset', []);
    }

    private function log(string $type, array $details): void
    {
        $this->transactionLog?->append($type, $details);
    }
}
