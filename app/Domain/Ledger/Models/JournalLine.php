<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * @property int $id
 * @property string $journal_entry_id
 * @property string $account_code
 * @property string $debit_amount
 * @property string $credit_amount
 * @property string $currency
 * @property string|null $narrative
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static JournalLine|null find(mixed $id, array<int, string> $columns = ['*'])
 * @method static JournalLine create(array<string, mixed> $attributes = [])
 */
class JournalLine extends Model
{
    protected $table = 'journal_lines';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'debit_amount'  => '0.0000',
        'credit_amount' => '0.0000',
        'currency'      => 'USD',
    ];

    /**
     * Journal lines inherit their entry's immutability: once the parent
     * entry is posted (or reversed), its lines can neither be changed nor
     * deleted. Corrections are mirror entries, never edits.
     */
    protected static function booted(): void
    {
        $guard = function (JournalLine $line): void {
            if (! $line->exists) {
                return;
            }

            $entry = $line->entry()->first();

            if ($entry !== null && $entry->status->isTerminal()) {
                throw new RuntimeException(
                    "Journal line {$line->id} belongs to a {$entry->status->value} entry and is immutable."
                );
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    /**
     * Parent journal entry.
     *
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id', 'id');
    }

    /**
     * Ledger account associated with this line.
     *
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_code', 'code');
    }
}
