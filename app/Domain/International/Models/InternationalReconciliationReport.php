<?php

declare(strict_types=1);

namespace App\Domain\International\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Result of one reconciliation run: the internal clearing account
 * (general ledger) against the domain's own open transfers, plus — for
 * statement matching — the external statement rows matched against
 * settled transfers. `variance` is an exact decimal string (bcmath);
 * anything other than zero is a discrepancy for manual review.
 *
 * @property string $id
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property string $domain
 * @property string $gl_account_code
 * @property string $gl_balance
 * @property string $domain_balance
 * @property string $variance
 * @property string $status
 * @property array<string, mixed>|null $details
 */
class InternationalReconciliationReport extends Model
{
    use HasUuids;

    protected $table = 'international_reconciliation_reports';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'details'      => 'array',
    ];

    public const STATUS_MATCHED = 'matched';

    public const STATUS_DISCREPANCY = 'discrepancy';
}
