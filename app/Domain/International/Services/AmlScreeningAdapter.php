<?php

declare(strict_types=1);

namespace App\Domain\International\Services;

use App\Domain\Compliance\Contracts\SanctionsScreeningInterface;
use App\Domain\International\Contracts\InternationalScreeningInterface;
use App\Domain\International\Contracts\ScreeningResult;
use App\Domain\International\Enums\ScreeningOutcome;
use App\Domain\International\Models\Beneficiary;
use App\Domain\International\Models\Counterparty;
use Throwable;

/**
 * Screening adapter over the existing SanctionsScreeningInterface.
 *
 * Bind this ONLY with a real sanctions provider behind the interface.
 * The local simulation that ships behind that interface is NOT a
 * sanctions clearance: a `clear` from a simulated provider must never
 * be treated as production clearance. Any provider error maps to
 * `error` (fail-closed: the transfer stays in screening).
 */
final class AmlScreeningAdapter implements InternationalScreeningInterface
{
    public function __construct(
        private readonly SanctionsScreeningInterface $sanctions,
    ) {
    }

    public function screen(Counterparty $counterparty, Beneficiary $beneficiary): ScreeningResult
    {
        try {
            $result = $this->sanctions->screenIndividual([
                'name'        => $counterparty->full_name,
                'nationality' => $counterparty->country,
            ]);
        } catch (Throwable $e) {
            return new ScreeningResult(
                ScreeningOutcome::ERROR,
                $this->getName(),
                0,
                'Screening provider error: ' . $e->getMessage(),
            );
        }

        $matches = (int) ($result['total_matches'] ?? 0);

        if ($matches > 0) {
            return new ScreeningResult(
                ScreeningOutcome::HIT,
                $this->getName(),
                $matches,
                "Sanctions screening returned {$matches} match(es); transfer must be rejected.",
            );
        }

        return new ScreeningResult(
            ScreeningOutcome::CLEAR,
            $this->getName(),
            0,
            'No sanctions matches returned by the configured provider.',
        );
    }

    public function getName(): string
    {
        return 'aml-screening:' . $this->sanctions->getName();
    }
}
