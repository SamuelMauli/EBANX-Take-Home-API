<?php

declare(strict_types=1);

namespace Ebanx\Infrastructure;

/**
 * File-based idempotency store.
 * Stores Idempotency-Key => serialized response for replay on retries.
 * TTL-based cleanup prevents unbounded growth.
 */
final class IdempotencyStore
{
    private const int TTL_SECONDS = 86400; // 24 hours

    private string $filePath;

    public function __construct(?string $filePath = null)
    {
        $this->filePath = $filePath ?? sys_get_temp_dir() . '/ebanx_idempotency.json';
    }

    /**
     * @return array{status: int, body: string}|null
     */
    public function get(string $key): ?array
    {
        $entries = $this->loadAll();

        if (!isset($entries[$key])) {
            return null;
        }

        $entry = $entries[$key];

        // Expired
        if (time() - $entry['timestamp'] > self::TTL_SECONDS) {
            return null;
        }

        return ['status' => $entry['status'], 'body' => $entry['body']];
    }

    public function set(string $key, int $status, string $body): void
    {
        $entries = $this->loadAll();

        // Cleanup expired entries on write
        $now = time();
        $entries = array_filter(
            $entries,
            static fn(array $entry): bool => ($now - $entry['timestamp']) <= self::TTL_SECONDS,
        );

        $entries[$key] = [
            'status' => $status,
            'body' => $body,
            'timestamp' => $now,
        ];

        $this->persist($entries);
    }

    public function clear(): void
    {
        $this->persist([]);
    }

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

    private function persist(array $entries): void
    {
        $dir = dirname($this->filePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->filePath,
            json_encode($entries, JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
    }
}
