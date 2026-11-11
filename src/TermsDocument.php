<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

use DateTimeImmutable;

/** Версия юридического документа. Код — произвольная строка продукта `[a-z0-9_]{1,64}` */
final readonly class TermsDocument
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public string $name,
        public ?string $description,
        public string $link,
        public string $code,
        public int $version,
    ) {
    }
}
