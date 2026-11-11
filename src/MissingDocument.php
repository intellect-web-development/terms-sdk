<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

/** Документ набора, из-за которого проверка акцепта не пройдена */
final readonly class MissingDocument
{
    public function __construct(
        public string $code,
        /** Название актуального документа; null, если документа нет (reason = document_not_found) */
        public ?string $name,
        public MissingReason $reason,
    ) {
    }
}
