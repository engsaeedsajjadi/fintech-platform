<?php

declare(strict_types=1);

namespace App\Domain\International\Services;

use App\Domain\International\Models\InternationalAuditLog;

/** Append-only audit writer for the International domain. */
final class InternationalAuditService
{
    /** @param array<string, mixed> $details */
    public function log(
        ?string $transferId,
        ?int $userId,
        string $action,
        array $details = [],
    ): InternationalAuditLog {
        return InternationalAuditLog::create([
            'international_transfer_id' => $transferId,
            'user_id'                   => $userId,
            'action'                    => $action,
            'details'                   => $details === [] ? null : $details,
        ]);
    }
}
