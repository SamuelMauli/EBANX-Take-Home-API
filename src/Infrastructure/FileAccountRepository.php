<?php

declare(strict_types=1);

namespace Ebanx\Infrastructure;

use Ebanx\Domain\Account;
use Ebanx\Domain\AccountRepositoryInterface;

/**
 * File-based repository for PHP's stateless request model.
 * Stores accounts as JSON in a temp file — persists between requests,
 * lost on server restart (which matches "durability is NOT a requirement").
 *
 * Uses flock() for full read-modify-write atomicity across concurrent PHP-FPM workers.
 * Re-entrant locking allows atomic() blocks to call find()/save() without deadlocking.
 */
final class FileAccountRepository implements AccountRepositoryInterface
{
    private string $filePath;
    private string $lockPath;
    private int $lockDepth = 0;
    /** @var resource|null */
    private $activeLockHandle = null;

    public function __construct(?string $filePath = null)
    {
        $this->filePath = $filePath ?? sys_get_temp_dir() . '/ebanx_accounts.json';
        $this->lockPath = $this->filePath . '.lock';
    }

    public function find(string $id): ?Account
    {
        $accounts = $this->withLock(fn () => $this->loadAll());

        if (!isset($accounts[$id])) {
            return null;
        }

        return new Account($id, $accounts[$id]);
    }

    public function save(Account $account): void
    {
        $this->withLock(function () use ($account): void {
            $accounts = $this->loadAll();
            $accounts[$account->getId()] = $account->getBalance();
            $this->writeFile($accounts);
        });
    }

    public function clear(): void
    {
        $this->withLock(fn () => $this->writeFile([]));
    }

    public function atomic(callable $callback): mixed
    {
        return $this->withLock($callback);
    }

    /**
     * Execute a callback under an exclusive file lock.
     * Re-entrant: if already inside a lock, the callback runs directly
     * without attempting to acquire again (prevents deadlock).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        // Already inside a lock — skip re-acquisition to prevent deadlock
        if ($this->lockDepth > 0) {
            return $callback();
        }

        $dir = dirname($this->lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $lockHandle = fopen($this->lockPath, 'c');
        if ($lockHandle === false) {
            throw new \RuntimeException('Cannot open lock file: ' . $this->lockPath);
        }

        $this->lockDepth++;
        $this->activeLockHandle = $lockHandle;

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new \RuntimeException('Cannot acquire lock: ' . $this->lockPath);
            }

            $result = $callback();

            flock($lockHandle, LOCK_UN);

            return $result;
        } finally {
            $this->lockDepth--;
            $this->activeLockHandle = null;
            fclose($lockHandle);
        }
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
    private function writeFile(array $accounts): void
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
