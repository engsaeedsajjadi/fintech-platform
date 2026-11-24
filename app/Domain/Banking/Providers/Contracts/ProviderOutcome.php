<?php

declare(strict_types=1);

namespace App\Domain\Banking\Providers\Contracts;

/**
 * The only three things a provider call can honestly report.
 *
 * - SUCCESS: the provider confirmed the outcome.
 * - FAILED: the provider definitively rejected it.
 * - UNKNOWN: timeout, ambiguous response, lost webhook — the money state
 *   is not known. UNKNOWN is never auto-failed and never retried as a
 *   new money movement; it is resolved by a status query, a webhook, or
 *   manual review.
 */
enum ProviderOutcome: string
{
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case UNKNOWN = 'unknown';
}
