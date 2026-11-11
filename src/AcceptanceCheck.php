<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

/** Результат проверки: приняты ли актуальные версии документов по набору кодов */
final readonly class AcceptanceCheck
{
    /**
     * @param bool                  $accepted  Все ли документы набора приняты
     * @param list<AcceptanceItem>  $checkInfo Документ и статус по каждому коду, чей документ найден
     * @param list<MissingDocument> $missing   Что не засчитано: нет документа или не принят
     */
    public function __construct(
        public int $userId,
        public bool $accepted,
        public array $checkInfo,
        public array $missing,
    ) {
    }
}
