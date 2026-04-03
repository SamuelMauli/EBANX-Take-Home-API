<?php

declare(strict_types=1);

namespace Ebanx\Domain\Exception;

final class InsufficientFundsException extends DomainException
{
    public static function forWithdraw(string $accountId, int $requested, int $available): self
    {
        return new self(
            sprintf(
                'Cannot withdraw %d from account \'%s\': available balance is %d',
                $requested,
                $accountId,
                $available
            )
        );
    }
}
