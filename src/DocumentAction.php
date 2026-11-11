<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

use DateTimeImmutable;

/** Запись журнала: пользователь принял или отозвал документ */
final readonly class DocumentAction
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public int $userId,
        public DateTimeImmutable $datetime,
        public ActionType $type,
        public string $userAgent,
        public string $userIp,
    ) {
    }
}
