<?php

declare(strict_types=1);

namespace App\Domain\International\Contracts;

use App\Domain\International\Models\Beneficiary;
use App\Domain\International\Models\Counterparty;

/**
 * Compliance screening integration point for international transfers.
 *
 * Implementations screen a counterparty + beneficiary against sanctions
 * / PEP / adverse-media lists. The default binding is
 * ManualReviewScreeningAdapter, which always answers
 * `manual_review_required`: no provider is connected, so no transfer
 * is ever auto-cleared. AmlScreeningAdapter wraps the existing
 * SanctionsScreeningInterface; bind it ONLY with a real sanctions
 * provider behind that interface — the local simulation behind it is
 * not a sanctions clearance and must never auto-approve a transfer.
 */
interface InternationalScreeningInterface
{
    public function screen(Counterparty $counterparty, Beneficiary $beneficiary): ScreeningResult;

    public function getName(): string;
}
