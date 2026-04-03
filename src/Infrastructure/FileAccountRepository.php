<?php

declare(strict_types=1);

namespace Ebanx\Infrastructure;

use Ebanx\Domain\Account;
use Ebanx\Domain\AccountRepositoryInterface;

/**
 * File-based repository for PHP's stateless request model.
 * Stores accounts as JSON in a temp file — persists between requests,
 * lost on server restart (which matches "durability is NOT a requirement").
 */
final class FileAccountRepository implements AccountRepositoryInterface
{
    private string $filePath;

    public function __construct(?string $filePath = null)
    {
        $this->filePath = $filePath ?? sys_get_temp_dir() . '/ebanx_accounts.json';
    }

    public function find(string $id): ?Account
    {
        $accounts = $this->loadAll();

        if (!isset($accounts[$id])) {
            return null;
        }

        return new Account($id, $accounts[$id]);
    }

    public function save(Account $account): void
    {
        $accounts = $this->loadAll();
        $accounts[$account->getId()] = $account->getBalance();
        $this->persist($accounts);
    }

    public function clear(): void
    {
        $this->persist([]);
    }

    /**
     * @return array<string, int> Account ID => balance
     */
    private function loadAll(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $content = file_get_contents($this->filePath);

        if ($content === false || $content === '') {
            return [];
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, int> $accounts
     */
    private function persist(array $accounts): void
    {
        $dir = dirname($this->filePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->filePath,
            json_encode($accounts, JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
    }
}
