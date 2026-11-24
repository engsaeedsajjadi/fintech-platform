<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable idempotency record for ledger postings.
 *
 * A client-supplied idempotency key is stored UNIQUE. Replaying the same key
 * returns the original journal entry instead of posting a second one. The
 * `result` payload is the stored outcome so a replay can return the exact
 * original result without re-executing.
 *
 * @property string $id
 * @property string $key
 * @property string $domain
 * @property string|null $journal_entry_id
 * @property array<string, mixed>|null $result
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static IdempotencyKey|null find(mixed $id, array<int, string> $columns = ['*'])
 * @method static IdempotencyKey create(array<string, mixed> $attributes = [])
 */
class IdempotencyKey extends Model
{
    use HasUuids;

    protected $table = 'ledger_idempotency_keys';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string|class-string> */
    protected $casts = [
        'result' => 'array',
    ];
}
