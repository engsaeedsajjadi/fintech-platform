<?php

declare(strict_types=1);

namespace App\Domain\International\Services;

use App\Domain\International\Contracts\InternationalScreeningInterface;
use App\Domain\International\Contracts\ScreeningResult;
use App\Domain\International\Enums\ScreeningOutcome;
use App\Domain\International\Models\Beneficiary;
use App\Domain\International\Models\Counterparty;

/**
 * Default screening adapter: no provider is connected.
 *
 * Every check answers `manual_review_required`: the transfer stays in
 * `screening` until a compliance officer records a decision. This is
 * the fail-closed default — a transfer is never auto-cleared by the
 * absence of a provider.
 */
final class ManualReviewScreeningAdapter implements InternationalScreeningInterface
{
    public function screen(Counterparty $counterparty, Beneficiary $beneficiary): ScreeningResult
    {
        return new ScreeningResult(
            ScreeningOutcome::MANUAL_REVIEW_REQUIRED,
            $this->getName(),
            0,
            'No screening provider is connected; a compliance officer must review this counterparty before approval.',
        );
    }

    public function getName(): string
    {
        return 'manual-review';
    }
}
