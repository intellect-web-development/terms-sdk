<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

/** Актуальный документ по коду и статус его принятия пользователем (null, если пользователь с ним не взаимодействовал) */
final readonly class AcceptanceItem
{
    public function __construct(
        public string $code,
        public TermsDocument $document,
        public ?DocumentAcceptanceStatus $documentAcceptanceStatus,
    ) {
    }
}
