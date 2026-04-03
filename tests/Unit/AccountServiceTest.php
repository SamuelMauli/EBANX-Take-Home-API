<?php

declare(strict_types=1);

namespace Ebanx\Tests\Unit;

use Ebanx\Domain\Account;
use Ebanx\Domain\AccountService;
use Ebanx\Domain\Exception\AccountNotFoundException;
use Ebanx\Infrastructure\InMemoryAccountRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AccountServiceTest extends TestCase
{
    private InMemoryAccountRepository $repository;
    private AccountService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryAccountRepository();
        $this->service = new AccountService($this->repository);
    }

    #[Test]
    public function get_balance_of_existing_account(): void
    {
        $this->repository->save(new Account('100', 50));

        $this->assertSame(50, $this->service->getBalance('100'));
    }

    #[Test]
    public function get_balance_of_nonexistent_account_throws(): void
    {
        $this->expectException(AccountNotFoundException::class);

        $this->service->getBalance('999');
    }

    #[Test]
    public function deposit_creates_new_account_if_not_exists(): void
    {
        $account = $this->service->deposit('100', 10);

        $this->assertSame('100', $account->getId());
        $this->assertSame(10, $account->getBalance());
    }

    #[Test]
    public function deposit_to_existing_account_adds_to_balance(): void
    {
        $this->service->deposit('100', 10);
        $account = $this->service->deposit('100', 10);

        $this->assertSame(20, $account->getBalance());
    }

    #[Test]
    public function deposit_persists_account_in_repository(): void
    {
        $this->service->deposit('100', 10);

        $this->assertNotNull($this->repository->find('100'));
        $this->assertSame(10, $this->repository->find('100')->getBalance());
    }

    #[Test]
    public function withdraw_from_existing_account(): void
    {
        $this->service->deposit('100', 20);

        $account = $this->service->withdraw('100', 5);

        $this->assertSame('100', $account->getId());
        $this->assertSame(15, $account->getBalance());
    }

    #[Test]
    public function withdraw_from_nonexistent_account_throws(): void
    {
        $this->expectException(AccountNotFoundException::class);

        $this->service->withdraw('200', 10);
    }

    #[Test]
    public function withdraw_updates_repository(): void
    {
        $this->service->deposit('100', 20);
        $this->service->withdraw('100', 5);

        $this->assertSame(15, $this->repository->find('100')->getBalance());
    }

    #[Test]
    public function transfer_between_existing_accounts(): void
    {
        $this->service->deposit('100', 15);
        $this->repository->save(new Account('300'));

        $result = $this->service->transfer('100', '300', 15);

        $this->assertSame(0, $result['origin']->getBalance());
        $this->assertSame(15, $result['destination']->getBalance());
    }

    #[Test]
    public function transfer_creates_destination_if_not_exists(): void
    {
        $this->service->deposit('100', 15);

        $result = $this->service->transfer('100', '300', 15);

        $this->assertSame('300', $result['destination']->getId());
        $this->assertSame(15, $result['destination']->getBalance());
    }

    #[Test]
    public function transfer_from_nonexistent_origin_throws(): void
    {
        $this->expectException(AccountNotFoundException::class);

        $this->service->transfer('200', '300', 15);
    }

    #[Test]
    public function transfer_persists_both_accounts(): void
    {
        $this->service->deposit('100', 50);

        $this->service->transfer('100', '200', 30);

        $this->assertSame(20, $this->repository->find('100')->getBalance());
        $this->assertSame(30, $this->repository->find('200')->getBalance());
    }

    #[Test]
    public function reset_clears_all_accounts(): void
    {
        $this->service->deposit('100', 10);
        $this->service->deposit('200', 20);

        $this->service->reset();

        $this->expectException(AccountNotFoundException::class);
        $this->service->getBalance('100');
    }

    #[Test]
    public function reset_allows_fresh_start(): void
    {
        $this->service->deposit('100', 50);
        $this->service->reset();

        $account = $this->service->deposit('100', 10);

        $this->assertSame(10, $account->getBalance());
    }

    #[Test]
    public function full_ebanx_scenario_at_domain_level(): void
    {
        $this->service->reset();

        try {
            $this->service->getBalance('1234');
            $this->fail('Expected AccountNotFoundException');
        } catch (AccountNotFoundException) {
        }

        $acc = $this->service->deposit('100', 10);
        $this->assertSame(10, $acc->getBalance());

        $acc = $this->service->deposit('100', 10);
        $this->assertSame(20, $acc->getBalance());

        $this->assertSame(20, $this->service->getBalance('100'));

        try {
            $this->service->withdraw('200', 10);
            $this->fail('Expected AccountNotFoundException');
        } catch (AccountNotFoundException) {
        }

        $acc = $this->service->withdraw('100', 5);
        $this->assertSame(15, $acc->getBalance());

        $result = $this->service->transfer('100', '300', 15);
        $this->assertSame(0, $result['origin']->getBalance());
        $this->assertSame(15, $result['destination']->getBalance());

        try {
            $this->service->transfer('200', '300', 15);
            $this->fail('Expected AccountNotFoundException');
        } catch (AccountNotFoundException) {
        }
    }

    #[Test]
    public function get_balance_does_not_alter_state(): void
    {
        $this->service->deposit('100', 50);

        $this->service->getBalance('100');
        $this->service->getBalance('100');
        $this->service->getBalance('100');

        $this->assertSame(50, $this->repository->find('100')->getBalance());
    }

    #[Test]
    public function transfer_with_insufficient_funds_leaves_both_accounts_unchanged(): void
    {
        $this->service->deposit('100', 10);
        $this->service->deposit('200', 20);

        try {
            $this->service->transfer('100', '200', 50);
        } catch (\Ebanx\Domain\Exception\InsufficientFundsException) {
        }

        $this->assertSame(10, $this->repository->find('100')->getBalance());
        $this->assertSame(20, $this->repository->find('200')->getBalance());
    }

    #[Test]
    public function deposit_withdraw_sequence_maintains_correct_state(): void
    {
        $this->service->deposit('100', 100);
        $this->service->withdraw('100', 30);
        $this->service->deposit('100', 50);
        $this->service->withdraw('100', 20);

        $this->assertSame(100, $this->service->getBalance('100'));
        $this->assertSame(100, $this->repository->find('100')->getBalance());
    }
}
