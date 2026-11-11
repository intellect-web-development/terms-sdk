<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

/** Журнал акцептов и отзывов пользователя по актуальной версии документа */
final readonly class DocumentHistory
{
    /** @param list<DocumentAction> $actions */
    public function __construct(
        public TermsDocument $document,
        public array $actions,
    ) {
    }
}
