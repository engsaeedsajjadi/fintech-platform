<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

/**
 * Canonical transaction state machine (TARGET_ARCHITECTURE.md §3.3).
 *
 * Every payment / transfer / deposit / withdrawal intent maps its provider
 * specific status onto one of these values. `initiated`, `pending`,
 * `processing` and `unknown` are non-terminal; everything else is terminal
 * and never transitions again — a correction is a new (reversing) entry,
 * never an edit.
 *
 * `unknown` is entered on timeout, ambiguous response, or lost webhook.
 * It is resolved only by a status query, a webhook, or manual review —
 * never by retrying the money movement and never by auto-failing and
 * releasing a hold.
 */
enum TransactionState: string
{
    case INITIATED = 'initiated';
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SUCCESSFUL = 'successful';
    case FAILED = 'failed';
    case REVERSED = 'reversed';
    case UNKNOWN = 'unknown';
    case REQUIRES_MANUAL_REVIEW = 'requires-manual-review';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::SUCCESSFUL, self::FAILED, self::REVERSED => true,
            default                                        => false,
        };
    }

    public function isNonTerminal(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Map a legacy free-text status (e.g. PaymentTransaction's
     * pending/completed/failed) onto the canonical vocabulary.
     * Unknown strings map to REQUIRES_MANUAL_REVIEW, never to a terminal
     * state — an unclassifiable outcome must be reviewed, not assumed.
     */
    public static function fromLegacy(string $status): self
    {
        return match (strtolower($status)) {
            'initiated'                                                         => self::INITIATED,
            'pending'                                                           => self::PENDING,
            'processing', 'submitted'                                           => self::PROCESSING,
            'completed', 'successful', 'success'                                => self::SUCCESSFUL,
            'failed', 'failure'                                                 => self::FAILED,
            'reversed'                                                          => self::REVERSED,
            'unknown'                                                           => self::UNKNOWN,
            'requires-manual-review', 'requires_manual_review', 'manual-review' => self::REQUIRES_MANUAL_REVIEW,
            default                                                             => self::REQUIRES_MANUAL_REVIEW,
        };
    }
}
