<?php

declare(strict_types=1);

namespace App\Domain\International\Services;

use App\Domain\International\Contracts\InternationalScreeningInterface;
use App\Domain\International\Contracts\ScreeningResult;
use App\Domain\International\Models\InternationalTransfer;
use InvalidArgumentException;

/**
 * Runs a screening check for a transfer and records the outcome on the
 * counterparty and the transfer. The service itself never approves or
 * rejects — the caller (InternationalTransferService) maps the outcome
 * onto the state machine.
 */
final class InternationalScreeningService
{
    private InternationalScreeningInterface $adapter;

    public function __construct(
        private readonly ?InternationalAuditService $audit = null,
    ) {
        // No container binding exists for the screening adapter (no
        // provider is connected), so manual review is the default.
        // A real provider is injected via setAdapter() — or via
        // InternationalServiceProvider once it is registered.
        $this->adapter = new ManualReviewScreeningAdapter();
    }

    public function setAdapter(InternationalScreeningInterface $adapter): void
    {
        $this->adapter = $adapter;
    }

    public function screen(InternationalTransfer $transfer): ScreeningResult
    {
        $counterparty = $transfer->counterparty;
        $beneficiary = $transfer->beneficiary;

        if ($counterparty === null || $beneficiary === null) {
            throw new InvalidArgumentException('Transfer has no counterparty or beneficiary to screen.');
        }

        $result = $this->adapter->screen($counterparty, $beneficiary);

        $counterparty->update([
            'screening_status' => $result->outcome->value,
            'last_screened_at' => now(),
        ]);

        $transfer->update([
            'screening_status' => $result->outcome->value,
            'screening_notes'  => $result->detail,
        ]);

        $this->audit?->log($transfer->id, (int) $transfer->user_id, 'transfer.screened', [
            'provider' => $result->provider,
            'outcome'  => $result->outcome->value,
            'matches'  => $result->matches,
        ]);

        return $result;
    }

    public function adapterName(): string
    {
        return $this->adapter->getName();
    }
}
