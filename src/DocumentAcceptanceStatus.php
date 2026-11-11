<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

use DateTimeImmutable;

/** Статус принятия конкретной версии документа конкретным пользователем */
final readonly class DocumentAcceptanceStatus
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public int $userId,
        public int $documentId,
        public Status $status,
    ) {
    }
}
