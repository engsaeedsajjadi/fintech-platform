<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Enums;

enum DocumentStatus: string
{
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
