<?php

declare(strict_types=1);

namespace Ebanx\Tests\Unit;

use Ebanx\Domain\Account;
use Ebanx\Infrastructure\InMemoryAccountRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InMemoryAccountRepositoryTest extends TestCase
{
    private InMemoryAccountRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryAccountRepository();
    }

    #[Test]
    public function find_returns_null_for_nonexistent_account(): void
    {
        $this->assertNull($this->repository->find('999'));
    }

    #[Test]
    public function save_and_find_returns_same_account_instance(): void
    {
        $account = new Account('100', 50);

        $this->repository->save($account);
        $found = $this->repository->find('100');

        $this->assertSame($account, $found);
    }

    #[Test]
    public function save_overwrites_existing_account(): void
    {
        $account1 = new Account('100', 10);
        $account2 = new Account('100', 99);

        $this->repository->save($account1);
        $this->repository->save($account2);

        $this->assertSame($account2, $this->repository->find('100'));
    }

    #[Test]
    public function find_isolates_accounts_by_id(): void
    {
        $account1 = new Account('100', 10);
        $account2 = new Account('200', 20);

        $this->repository->save($account1);
        $this->repository->save($account2);

        $this->assertSame(10, $this->repository->find('100')->getBalance());
        $this->assertSame(20, $this->repository->find('200')->getBalance());
    }

    #[Test]
    public function clear_removes_all_accounts(): void
    {
        $this->repository->save(new Account('100', 10));
        $this->repository->save(new Account('200', 20));

        $this->repository->clear();

        $this->assertNull($this->repository->find('100'));
        $this->assertNull($this->repository->find('200'));
    }

    #[Test]
    public function clear_on_empty_repository_does_not_throw(): void
    {
        $this->repository->clear();

        $this->assertNull($this->repository->find('100'));
    }

    #[Test]
    public function mutations_on_saved_account_are_reflected_on_find(): void
    {
        $account = new Account('100', 10);
        $this->repository->save($account);

        $account->deposit(5);

        $this->assertSame(15, $this->repository->find('100')->getBalance());
    }
}
