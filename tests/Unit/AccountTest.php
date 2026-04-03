<?php

declare(strict_types=1);

namespace Ebanx\Tests\Unit;

use Ebanx\Domain\Account;
use Ebanx\Domain\Exception\InsufficientFundsException;
use Ebanx\Domain\Exception\InvalidAmountException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    #[Test]
    public function new_account_starts_with_zero_balance(): void
    {
        $account = new Account('100');

        $this->assertSame('100', $account->getId());
        $this->assertSame(0, $account->getBalance());
    }

    #[Test]
    public function new_account_with_initial_balance(): void
    {
        $account = new Account('100', 50);

        $this->assertSame(50, $account->getBalance());
    }

    #[Test]
    public function deposit_increases_balance(): void
    {
        $account = new Account('100');

        $account->deposit(10);
        $this->assertSame(10, $account->getBalance());

        $account->deposit(10);
        $this->assertSame(20, $account->getBalance());
    }

    #[Test]
    public function deposit_zero_throws_invalid_amount(): void
    {
        $account = new Account('100');

        $this->expectException(InvalidAmountException::class);
        $account->deposit(0);
    }

    #[Test]
    public function deposit_negative_throws_invalid_amount(): void
    {
        $account = new Account('100');

        $this->expectException(InvalidAmountException::class);
        $account->deposit(-5);
    }

    #[Test]
    public function withdraw_decreases_balance(): void
    {
        $account = new Account('100', 20);

        $account->withdraw(5);

        $this->assertSame(15, $account->getBalance());
    }

    #[Test]
    public function withdraw_entire_balance_leaves_zero(): void
    {
        $account = new Account('100', 15);

        $account->withdraw(15);

        $this->assertSame(0, $account->getBalance());
    }

    #[Test]
    public function withdraw_more_than_balance_throws_insufficient_funds(): void
    {
        $account = new Account('100', 10);

        $this->expectException(InsufficientFundsException::class);
        $account->withdraw(11);
    }

    #[Test]
    public function withdraw_zero_throws_invalid_amount(): void
    {
        $account = new Account('100');

        $this->expectException(InvalidAmountException::class);
        $account->withdraw(0);
    }

    #[Test]
    public function withdraw_negative_throws_invalid_amount(): void
    {
        $account = new Account('100');

        $this->expectException(InvalidAmountException::class);
        $account->withdraw(-1);
    }

    #[Test]
    public function account_id_is_string_even_if_numeric(): void
    {
        $account = new Account('300');

        $this->assertSame('300', $account->getId());
        $this->assertIsString($account->getId());
    }

    #[Test]
    public function multiple_operations_maintain_correct_balance(): void
    {
        $account = new Account('100');

        $account->deposit(100);
        $account->withdraw(30);
        $account->deposit(50);
        $account->withdraw(20);

        $this->assertSame(100, $account->getBalance());
    }
}
