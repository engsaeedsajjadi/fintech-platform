<?php

declare(strict_types=1);

namespace App\Domain\International\Enums;

/** Lifecycle of a settlement instruction attached to a transfer. */
enum SettlementInstructionStatus: string
{
    case PENDING = 'pending';
    case EXECUTED = 'executed';
    case CANCELLED = 'cancelled';
    case BLOCKED = 'blocked';
}
