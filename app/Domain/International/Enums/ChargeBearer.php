<?php

declare(strict_types=1);

namespace App\Domain\International\Enums;

/** Who bears the transfer charges (SWIFT gpi charge-bearer convention). */
enum ChargeBearer: string
{
    case OUR = 'our';
    case SHA = 'sha';
    case BEN = 'ben';
}
