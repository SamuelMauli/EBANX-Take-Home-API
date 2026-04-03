<?php

declare(strict_types=1);

namespace Ebanx\Domain;

use Ebanx\Domain\Exception\InsufficientFundsException;
use Ebanx\Domain\Exception\InvalidAmountException;

final class Account
{
    private int $balance;

    public function __construct(
        private readonly string $id,
        int $balance = 0,
    ) {
        $this->balance = $balance;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBalance(): int
    {
        return $this->balance;
    }

    public function deposit(int $amount): void
    {
        if ($amount <= 0) {
            throw InvalidAmountException::notPositive($amount);
        }

        $this->balance += $amount;
    }

    public function withdraw(int $amount): void
    {
        if ($amount <= 0) {
            throw InvalidAmountException::notPositive($amount);
        }

        if ($amount > $this->balance) {
            throw InsufficientFundsException::forWithdraw(
                $this->id,
                $amount,
                $this->balance
            );
        }

        $this->balance -= $amount;
    }
}
