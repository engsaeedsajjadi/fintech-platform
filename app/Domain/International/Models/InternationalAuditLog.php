<?php

declare(strict_types=1);

namespace App\Domain\International\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail for the International domain. Every state
 * transition, screening outcome, ledger posting, dispatch refusal and
 * reconciliation run writes one row here. Rows are never updated.
 *
 * @property int $id
 * @property string|null $international_transfer_id
 * @property int|null $user_id
 * @property string $action
 * @property array<string, mixed>|null $details
 */
class InternationalAuditLog extends Model
{
    protected $table = 'international_audit_logs';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'details' => 'array',
    ];
}
