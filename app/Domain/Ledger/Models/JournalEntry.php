<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Enums\EntryStatus;
use App\Domain\Ledger\ValueObjects\DecimalMath;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * @property string $id
 * @property string $entry_number
 * @property string $description
 * @property \Illuminate\Support\Carbon|null $posted_at
 * @property EntryStatus $status
 * @property string|null $source_domain
 * @property string|null $source_event_id
 * @property string|null $idempotency_key
 * @property string|null $reversed_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static JournalEntry|null find(mixed $id, array<int, string> $columns = ['*'])
 * @method static JournalEntry|null first(array<int, string> $columns = ['*'])
 * @method static JournalEntry firstOrFail(array<int, string> $columns = ['*'])
 * @method static JournalEntry create(array<string, mixed> $attributes = [])
 */
class JournalEntry extends Model
{
    use HasUuids;

    protected $table = 'journal_entries';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @var array<string, string|class-string>
     */
    protected $casts = [
        'status'    => EntryStatus::class,
        'posted_at' => 'datetime',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => EntryStatus::DRAFT,
    ];

    /**
     * Posted / reversed entries are immutable: the only permitted mutation
     * is the single status flip POSTED → REVERSED (with `reversed_by`) that
     * LedgerService::reverse() performs inside its transaction. Any other
     * update — and any delete — throws. Corrections are mirror entries,
     * never edits.
     */
    protected static function booted(): void
    {
        static::saving(function (JournalEntry $entry): void {
            if (! $entry->exists) {
                return;
            }

            // getOriginal() returns the raw DB value (string), not the cast enum.
            $rawOriginal = $entry->getOriginal('status');
            $originalStatus = $rawOriginal instanceof EntryStatus
                ? $rawOriginal
                : EntryStatus::tryFrom((string) $rawOriginal);

            if ($originalStatus === null || ! $originalStatus->isTerminal()) {
                return;
            }

            // The only permitted mutation of a terminal entry is the
            // POSTED → REVERSED flip (with reversed_by) performed by
            // LedgerService::reverse(). updated_at is allowed: Eloquent
            // touches it on every update.
            $isReversalFlip = $originalStatus === EntryStatus::POSTED
                && $entry->status === EntryStatus::REVERSED
                && count(array_diff(array_keys($entry->getDirty()), ['status', 'reversed_by', 'updated_at'])) === 0
                && in_array('status', array_keys($entry->getDirty()), true);

            if (! $isReversalFlip) {
                throw new RuntimeException(
                    "Journal entry {$entry->id} is {$originalStatus->value} and immutable; corrections must be mirror (reversal) entries."
                );
            }
        });

        static::deleting(function (JournalEntry $entry): void {
            throw new RuntimeException(
                "Journal entry {$entry->id} cannot be deleted; corrections must be mirror (reversal) entries."
            );
        });
    }

    /**
     * Journal lines for this entry.
     *
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id', 'id');
    }

    /**
     * Returns true when the sum of debits equals the sum of credits across all lines.
     * Exact decimal arithmetic — never floats.
     */
    public function isBalanced(): bool
    {
        $lines = $this->lines()->get();

        $totalDebit = '0';
        $totalCredit = '0';
        foreach ($lines as $line) {
            $totalDebit = DecimalMath::add($totalDebit, (string) $line->debit_amount, 4);
            $totalCredit = DecimalMath::add($totalCredit, (string) $line->credit_amount, 4);
        }

        return DecimalMath::cmp($totalDebit, $totalCredit, 4) === 0;
    }
}
