<?php

declare(strict_types=1);

namespace App\Domain\International\Enums;

/** Purpose of an international transfer (regulatory reporting / screening). */
enum TransferPurpose: string
{
    case FAMILY_SUPPORT = 'family_support';
    case EDUCATION = 'education';
    case MEDICAL = 'medical';
    case SALARY = 'salary';
    case GOODS_PAYMENT = 'goods_payment';
    case SERVICES_PAYMENT = 'services_payment';
    case INVESTMENT = 'investment';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FAMILY_SUPPORT   => 'Family support',
            self::EDUCATION        => 'Education',
            self::MEDICAL          => 'Medical',
            self::SALARY           => 'Salary',
            self::GOODS_PAYMENT    => 'Goods payment',
            self::SERVICES_PAYMENT => 'Services payment',
            self::INVESTMENT       => 'Investment',
            self::OTHER            => 'Other',
        };
    }

    public function requiresDetail(): bool
    {
        return $this === self::OTHER;
    }
}
