<?php

declare(strict_types=1);

namespace Ebanx\Infrastructure;

/**
 * Append-only transaction log.
 * Every state-changing operation is recorded with a timestamp,
 * creating an immutable audit trail for compliance and debugging.
 */
final class TransactionLog
{
    private string $filePath;

    public function __construct(?string $filePath = null)
    {
        $this->filePath = $filePath ?? sys_get_temp_dir() . '/ebanx_transactions.log';
    }

    public function append(string $type, array $details): void
    {
        $entry = json_encode([
            'timestamp' => date('c'),
            'type' => $type,
            ...$details,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->filePath, $entry . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAll(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $content = file_get_contents($this->filePath);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $lines = array_filter(explode("\n", trim($content)));

        return array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );
    }

    public function clear(): void
    {
        if (file_exists($this->filePath)) {
            file_put_contents($this->filePath, '', LOCK_EX);
        }
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
