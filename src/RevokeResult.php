<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

/** Итог отзыва акцепта актуальной версии документа */
final readonly class RevokeResult
{
    public function __construct(
        public TermsDocument $document,
        public DocumentAcceptanceStatus $documentAcceptanceStatus,
    ) {
    }
}
