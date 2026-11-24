<?php

declare(strict_types=1);

namespace App\Domain\Banking\Models;

use App\Domain\Ledger\ValueObjects\DecimalMath;
use App\Support\DecimalString;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BankStatement
{
    public function __construct(
        public readonly string $id,
        public readonly string $bankCode,
        public readonly string $accountId,
        public readonly Carbon $periodFrom,
        public readonly Carbon $periodTo,
        public readonly string $format,
        /** Exact decimal strings in major units — never floats. */
        public readonly string $openingBalance,
        public readonly string $closingBalance,
        public readonly string $currency,
        public readonly Collection $transactions,
        public readonly array $summary,
        public readonly ?string $fileUrl,
        public readonly ?string $fileContent,
        public readonly Carbon $generatedAt,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Get total debits. Exact decimal string, never a float.
     *
     * @return string
     */
    public function getTotalDebits(): string
    {
        $total = '0';
        foreach ($this->transactions->filter(fn ($tx) => $tx->isDebit()) as $tx) {
            $total = DecimalMath::add($total, $tx->getAbsoluteAmount(), 4);
        }

        return $total;
    }

    /**
     * Get total credits. Exact decimal string, never a float.
     *
     * @return string
     */
    public function getTotalCredits(): string
    {
        $total = '0';
        foreach ($this->transactions->filter(fn ($tx) => $tx->isCredit()) as $tx) {
            $total = DecimalMath::add($total, $tx->getAbsoluteAmount(), 4);
        }

        return $total;
    }

    /**
     * Get net change. Exact decimal string, never a float.
     *
     * @return string
     */
    public function getNetChange(): string
    {
        return DecimalMath::sub($this->closingBalance, $this->openingBalance, 4);
    }

    /**
     * Get transaction count by type.
     */
    public function getTransactionCountByType(): array
    {
        return $this->transactions
            ->groupBy('category')
            ->map(fn ($group) => $group->count())
            ->toArray();
    }

    /**
     * Get average transaction amount. Exact decimal string, never a float.
     *
     * @return string
     */
    public function getAverageTransactionAmount(): string
    {
        if ($this->transactions->isEmpty()) {
            return '0.0000';
        }

        $total = '0';
        foreach ($this->transactions as $tx) {
            $total = DecimalMath::add($total, $tx->getAbsoluteAmount(), 4);
        }

        return DecimalMath::div($total, (string) $this->transactions->count(), 4);
    }

    /**
     * Check if statement is balanced.
     */
    public function isBalanced(): bool
    {
        $calculatedClosing = DecimalMath::sub(
            DecimalMath::add($this->openingBalance, $this->getTotalCredits(), 4),
            $this->getTotalDebits(),
            4
        );
        $diff = DecimalMath::sub($calculatedClosing, $this->closingBalance, 4);

        // Within one cent either way.
        return DecimalMath::cmp($diff, '0.01', 4) < 0 && DecimalMath::cmp($diff, '-0.01', 4) > 0;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'bank_code'       => $this->bankCode,
            'account_id'      => $this->accountId,
            'period_from'     => $this->periodFrom->toIso8601String(),
            'period_to'       => $this->periodTo->toIso8601String(),
            'format'          => $this->format,
            'opening_balance' => $this->openingBalance,
            'closing_balance' => $this->closingBalance,
            'currency'        => $this->currency,
            'transactions'    => $this->transactions->map(fn ($tx) => $tx->toArray())->toArray(),
            'summary'         => $this->summary,
            'file_url'        => $this->fileUrl,
            'generated_at'    => $this->generatedAt->toIso8601String(),
            'metadata'        => $this->metadata,
        ];
    }

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        $transactions = collect($data['transactions'] ?? [])
            ->map(fn ($tx) => BankTransaction::fromArray($tx));

        return new self(
            id: $data['id'],
            bankCode: $data['bank_code'],
            accountId: $data['account_id'],
            periodFrom: Carbon::parse($data['period_from']),
            periodTo: Carbon::parse($data['period_to']),
            format: $data['format'],
            openingBalance: DecimalString::fromNumeric($data['opening_balance'] ?? 0, 4) ?? '0.0000',
            closingBalance: DecimalString::fromNumeric($data['closing_balance'] ?? 0, 4) ?? '0.0000',
            currency: $data['currency'],
            transactions: $transactions,
            summary: $data['summary'] ?? [],
            fileUrl: $data['file_url'] ?? null,
            fileContent: $data['file_content'] ?? null,
            generatedAt: Carbon::parse($data['generated_at']),
            metadata: $data['metadata'] ?? [],
        );
    }
}
