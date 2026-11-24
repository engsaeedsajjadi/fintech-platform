<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Transactional outbox for external driver sync (e.g. TigerBeetle).
 *
 * The database journal is the source of truth and is committed first. The
 * driver sync runs after the commit; when it fails, a row is recorded here
 * with status `pending` instead of failing the business operation or being
 * forgotten. `ledger:retry-driver-sync` replays pending rows. A row is only
 * ever marked `synced` after the driver confirms the transfer.
 *
 * @property string $id
 * @property string $journal_entry_id
 * @property string $driver
 * @property string $status pending|synced
 * @property int $attempts
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $synced_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static LedgerDriverSync|null find(mixed $id, array<int, string> $columns = ['*'])
 * @method static LedgerDriverSync create(array<string, mixed> $attributes = [])
 */
class LedgerDriverSync extends Model
{
    use HasUuids;

    protected $table = 'ledger_driver_syncs';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @var array<string, string|class-string>
     */
    protected $casts = [
        'attempts'  => 'integer',
        'synced_at' => 'datetime',
    ];
}
