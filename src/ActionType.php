<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

/** Тип действия пользователя с документом */
enum ActionType: string
{
    case Acceptance = 'acceptance';
    case Revocation = 'revocation';

    /** @return list<string> */
    public static function states(): array
    {
        return array_column(self::cases(), 'value');
    }
}
