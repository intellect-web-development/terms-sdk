<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

/** Пройдено ли пояснение пользователем с учётом требуемой версии */
final readonly class AcknowledgementCheck
{
    public function __construct(
        public string $code,
        public bool $completed,
        /** Пройденная версия пояснения; null, если пользователь его не проходил */
        public ?int $version,
    ) {
    }
}
