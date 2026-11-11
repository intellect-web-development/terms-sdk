<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

/** Статус принятия документа пользователем */
enum Status: string
{
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case NotAccepted = 'not_accepted';

    /** @return list<string> */
    public static function states(): array
    {
        return array_column(self::cases(), 'value');
    }
}
