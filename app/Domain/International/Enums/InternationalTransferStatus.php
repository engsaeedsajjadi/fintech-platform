<?php

declare(strict_types=1);

namespace App\Domain\International\Enums;

/**
 * Controlled workflow for an international P2P transfer.
 *
 * draft → submitted → screening → approved → processing → settled
 *                                  ↘ rejected (terminal)
 * approved → reversed (cancel before money moves)
 * processing → failed | reversed | settled
 *
 * settled / rejected / failed / reversed are terminal. A correction is
 * never an edit of a settled transfer — it is a new transfer (or an
 * operator reversal entry). Every transition is enforced by
 * InternationalTransferService under a row lock and recorded in the
 * status-history + audit tables.
 */
enum InternationalTransferStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case SCREENING = 'screening';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case PROCESSING = 'processing';
    case SETTLED = 'settled';
    case FAILED = 'failed';
    case REVERSED = 'reversed';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::REJECTED,
            self::SETTLED,
            self::FAILED,
            self::REVERSED,
        ], true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT      => [self::SUBMITTED],
            self::SUBMITTED  => [self::SCREENING],
            self::SCREENING  => [self::APPROVED, self::REJECTED],
            self::APPROVED   => [self::PROCESSING, self::REVERSED],
            self::PROCESSING => [self::SETTLED, self::FAILED, self::REVERSED],
            default          => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
