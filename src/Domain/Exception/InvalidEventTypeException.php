<?php

declare(strict_types=1);

namespace Ebanx\Domain\Exception;

final class InvalidEventTypeException extends DomainException
{
    public static function unknown(string $type): self
    {
        return new self(
            sprintf('Unknown event type: \'%s\'', $type)
        );
    }
}
