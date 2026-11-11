<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

/** Почему документ из набора не засчитан при проверке акцепта */
enum MissingReason: string
{
    /** Документа с таким кодом нет в сервисе */
    case DocumentNotFound = 'document_not_found';
    /** Актуальная версия не принята (не принималась или отозвана) */
    case NotAccepted = 'not_accepted';

    /** @return list<string> */
    public static function states(): array
    {
        return array_column(self::cases(), 'value');
    }
}
