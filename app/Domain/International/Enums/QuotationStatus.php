<?php

declare(strict_types=1);

namespace App\Domain\International\Enums;

/** Lifecycle of an exchange quotation. Only `accepted` quotes can fund a transfer. */
enum QuotationStatus: string
{
    case QUOTED = 'quoted';
    case ACCEPTED = 'accepted';
    case EXPIRED = 'expired';
    case SUPERSEDED = 'superseded';
}
