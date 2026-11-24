<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class DocumentSubmitted extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.document_submitted';
    }
}
