<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

use DateTimeImmutable;

/** Пройденное пользователем пояснение */
final readonly class UserAcknowledgement
{
    public function __construct(
        public string $id,
        public string $code,
        public int $version,
        public DateTimeImmutable $completedAt,
    ) {
    }
}
