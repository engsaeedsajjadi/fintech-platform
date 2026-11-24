<?php

declare(strict_types=1);

namespace App\Domain\International\ValueObjects;

use Illuminate\Support\Str;

/**
 * Reference numbers for an international transfer.
 *
 * `INTL-…` is our own internal reference (unique per transfer).
 * `end_to_end_reference` is an internal end-to-end identifier in the
 * shape used by ISO 20022 remittance information — it is NOT a real
 * SWIFT gpi UETR (no UETR is issued here; there is no SWIFT
 * connectivity) and must never be presented to a customer or a bank as
 * one. The external provider reference (`external_reference`) is only
 * ever filled in by an operator from a real provider confirmation.
 */
final class InternationalReference
{
    private function __construct()
    {
    }

    public static function generate(): string
    {
        return 'INTL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(10));
    }

    public static function generateEndToEnd(): string
    {
        return 'E2E-' . Str::uuid()->toString();
    }
}
