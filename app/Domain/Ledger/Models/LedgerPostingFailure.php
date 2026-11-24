<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable record of a failed GL auto-posting (PostGlEntryListener).
 *
 * A failed posting is recorded here AND rethrown, so a replayed or retried
 * event can be reconciled from this table instead of vanishing into a log
 * line while the business operation looks successful.
 *
 * @property string $id
 * @property string $event_name
 * @property string|null $event_id
 * @property string|null $source_domain
 * @property string|null $idempotency_key
 * @property string|null $event_payload
 * @property string $error
 * @property string $status pending|resolved
 * @property int $attempts
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static LedgerPostingFailure|null find(mixed $id, array<int, string> $columns = ['*'])
 * @method static LedgerPostingFailure create(array<string, mixed> $attributes = [])
 */
class LedgerPostingFailure extends Model
{
    use HasUuids;

    protected $table = 'ledger_posting_failures';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @var array<string, string|class-string>
     */
    protected $casts = [
        'attempts'    => 'integer',
        'resolved_at' => 'datetime',
    ];
}
