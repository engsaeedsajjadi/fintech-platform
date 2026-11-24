<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class DocumentReviewed extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.document_reviewed';
    }
}
