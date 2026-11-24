<?php

declare(strict_types=1);

namespace App\Domain\International\Enums;

/** Outcome of a counterparty / beneficiary screening check. */
enum ScreeningOutcome: string
{
    case CLEAR = 'clear';
    case HIT = 'hit';
    case MANUAL_REVIEW_REQUIRED = 'manual_review_required';
    case ERROR = 'error';

    /** Whether this outcome may move a transfer out of screening by itself. */
    public function isDecisive(): bool
    {
        return in_array($this, [self::CLEAR, self::HIT], true);
    }
}
