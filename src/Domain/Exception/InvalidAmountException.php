<?php

declare(strict_types=1);

namespace Ebanx\Domain\Exception;

final class InvalidAmountException extends DomainException
{
    public static function notPositive(int $amount): self
    {
        return new self(
            sprintf('Amount must be positive, got: %d', $amount)
        );
    }
}
