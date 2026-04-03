<?php

declare(strict_types=1);

namespace Ebanx\Domain\Exception;

final class AccountNotFoundException extends DomainException
{
    public static function withId(string $id): self
    {
        return new self(
            sprintf('Account \'%s\' not found', $id)
        );
    }
}
