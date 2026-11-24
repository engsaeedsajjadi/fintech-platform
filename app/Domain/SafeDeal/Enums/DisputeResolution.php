<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Enums;

enum DisputeResolution: string
{
    case RELEASE_TO_SELLER = 'release_to_seller';
    case RETURN_TO_BUYER = 'return_to_buyer';
    case SPLIT = 'split';
}
