<?php

declare(strict_types=1);

namespace Ebanx\Tests\Unit;

use Ebanx\Domain\Account;
use Ebanx\Infrastructure\FileAccountRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileAccountRepositoryTest extends TestCase
{
    private string $tempFile;
    private FileAccountRepository $repository;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/ebanx_test_' . uniqid() . '.json';
        $this->repository = new FileAccountRepository($this->tempFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    #[Test]
    public function find_returns_null_for_nonexistent_account(): void
    {
        $this->assertNull($this->repository->find('999'));
    }

    #[Test]
    public function save_and_find_returns_account_with_same_data(): void
    {
        $account = new Account('100', 50);

        $this->repository->save($account);
        $found = $this->repository->find('100');

        $this->assertNotNull($found);
        $this->assertSame('100', $found->getId());
        $this->assertSame(50, $found->getBalance());
    }

    #[Test]
    public function persists_across_repository_instances(): void
    {
        $this->repository->save(new Account('100', 42));

        $freshRepository = new FileAccountRepository($this->tempFile);
        $found = $freshRepository->find('100');

        $this->assertNotNull($found);
        $this->assertSame(42, $found->getBalance());
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
    public function save_overwrites_existing_balance(): void
    {
        $this->repository->save(new Account('100', 10));
        $this->repository->save(new Account('100', 99));

        $found = $this->repository->find('100');
        $this->assertSame(99, $found->getBalance());
    }
}
