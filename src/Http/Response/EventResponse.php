<?php

declare(strict_types=1);

namespace Ebanx\Http\Response;

use Ebanx\Domain\Account;

final class EventResponse
{
    public static function deposit(Account $destination): array
    {
        return [
            'destination' => self::accountToArray($destination),
        ];
    }

    public static function withdraw(Account $origin): array
    {
        return [
            'origin' => self::accountToArray($origin),
        ];
    }

    public static function transfer(Account $origin, Account $destination): array
    {
        return [
            'origin' => self::accountToArray($origin),
            'destination' => self::accountToArray($destination),
        ];
    }

    private static function accountToArray(Account $account): array
    {
        return [
            'id' => $account->getId(),
            'balance' => $account->getBalance(),
        ];
    }
}
