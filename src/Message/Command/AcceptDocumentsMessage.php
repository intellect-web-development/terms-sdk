<?php

declare(strict_types=1);

namespace IWD\TermsSdk\Message\Command;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Команда продукта сервису Terms: принять за пользователя актуальные версии документов с указанными кодами
 * (например, документы регистрации). Продукт сам решает, какие коды принимать.
 */
#[AsMessage(
    transport: 'terms-commands-transport',
    serializedTypeName: 'terms.command.acceptDocuments',
)]
readonly class AcceptDocumentsMessage
{
    /**
     * @param int          $userId                Идентификатор пользователя в продукте
     * @param string       $userAgent             User-Agent, попадает в журнал акцептов
     * @param string       $ip                    IP, попадает в журнал акцептов
     * @param list<string> $codes                 Коды документов, актуальные версии которых принимаются
     * @param string       $integrationSystemCode Код системы-отправителя (APP_SLUG), по нему Terms находит Customer
     */
    public function __construct(
        public int $userId,
        public string $userAgent,
        public string $ip,
        public array $codes,
        public string $integrationSystemCode,
    ) {
    }
}
