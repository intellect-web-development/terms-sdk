<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

/** Итог акцепта актуальной версии документа */
final readonly class AcceptResult
{
    public function __construct(
        public TermsDocument $document,
        public DocumentAcceptanceStatus $documentAcceptanceStatus,
        /** Документ был принят ранее, ничего не изменилось */
        public bool $alreadyAccepted,
    ) {
    }
}
