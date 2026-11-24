<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Enums;

enum RiskLevel: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case REJECTED = 'rejected';
}
