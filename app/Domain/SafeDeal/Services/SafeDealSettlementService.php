<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Services;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\SafeDeal\Exceptions\SafeDealException;
use App\Domain\SafeDeal\Models\SafeDealTrade;

/**
 * Financial settlement for SafeDeal — the ONLY place SafeDeal touches money.
 *
 * Every posting goes through LedgerService::postIdempotent (approved ledger
 * path); no new ledger mechanism is invented here. Funding moves the buyer
 * amount into a per-trade virtual escrow account; settlement moves it to
 * the seller; a refund reverses the funding entry via LedgerService::reverse
 * (mirror entry — the original is never edited).
 *
 * These are VIRTUAL ledger accounts inside the platform ledger. They do
 * NOT move real customer funds and are not custody: real funding/custody
 * needs an authorized provider + legal approval (see docs/SAFEDEAL_ARCHITECTURE.md).
 */
class SafeDealSettlementService
{
    public function __construct(
        private readonly LedgerService $ledger,
    ) {
    }

    public function escrowAccountCode(SafeDealTrade $trade): string
    {
        return "SAFEDEAL:ESCROW:{$trade->trade_id}:{$trade->currency}";
    }

    public function buyerAccountCode(SafeDealTrade $trade): string
    {
        return "SAFEDEAL:BUYER:{$trade->buyer_id}:{$trade->currency}";
    }

    public function sellerAccountCode(SafeDealTrade $trade): string
    {
        return "SAFEDEAL:SELLER:{$trade->seller_id}:{$trade->currency}";
    }

    private function ensureAccount(string $code, string $name, AccountType $type, string $currency): void
    {
        LedgerAccount::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'type' => $type, 'currency' => $currency, 'is_active' => true],
        );
    }

    /**
     * Funding: buyer -> escrow. Idempotent on `safedeal-fund:{trade}`.
     */
    public function fund(SafeDealTrade $trade): JournalEntry
    {
        $amount = bcadd((string) $trade->amount, '0', 4);

        if (bccomp($amount, '0', 4) <= 0) {
            throw new SafeDealException('Cannot fund a trade with a non-positive amount.');
        }

        $this->ensureAccount($this->buyerAccountCode($trade), "SafeDeal buyer {$trade->buyer_id}", AccountType::LIABILITY, $trade->currency);
        $this->ensureAccount($this->escrowAccountCode($trade), "SafeDeal escrow {$trade->trade_id}", AccountType::LIABILITY, $trade->currency);

        $entry = $this->ledger->postIdempotent(
            "safedeal-fund:{$trade->trade_id}",
            "SafeDeal funding {$trade->trade_id}",
            [
                // Buyer balance decreases (liability, debit), escrow holding
                // increases (liability, credit).
                ['account_code' => $this->buyerAccountCode($trade), 'debit' => $amount, 'credit' => '0.0000', 'currency' => $trade->currency, 'narrative' => "SafeDeal funding {$trade->trade_id}"],
                ['account_code' => $this->escrowAccountCode($trade), 'debit' => '0.0000', 'credit' => $amount, 'currency' => $trade->currency, 'narrative' => "SafeDeal funding {$trade->trade_id}"],
            ],
            'SafeDeal',
            $trade->trade_id,
        );

        $this->rememberEntry($trade, $entry);

        return $entry;
    }

    /**
     * Settlement: escrow -> seller. Idempotent on `safedeal-settle:{trade}`.
     * For a SPLIT dispute resolution the seller share is posted and the
     * remainder is returned to the buyer in the same balanced entry set
     * (two entries: seller share + buyer remainder).
     *
     * @param array{buyer_share: string, seller_share: string}|null $split
     * @return list<JournalEntry>
     */
    public function settle(SafeDealTrade $trade, ?array $split = null): array
    {
        $amount = bcadd((string) $trade->amount, '0', 4);

        $this->ensureAccount($this->escrowAccountCode($trade), "SafeDeal escrow {$trade->trade_id}", AccountType::LIABILITY, $trade->currency);
        $this->ensureAccount($this->sellerAccountCode($trade), "SafeDeal seller {$trade->seller_id}", AccountType::LIABILITY, $trade->currency);
        $this->ensureAccount($this->buyerAccountCode($trade), "SafeDeal buyer {$trade->buyer_id}", AccountType::LIABILITY, $trade->currency);

        $entries = [];

        if ($split !== null) {
            $sellerShare = bcadd($split['seller_share'], '0', 4);
            $buyerShare = bcadd($split['buyer_share'], '0', 4);

            if (bccomp(bcadd($sellerShare, $buyerShare, 4), $amount, 4) !== 0) {
                throw new SafeDealException('Split shares must sum to the trade amount.');
            }

            if (bccomp($sellerShare, '0', 4) > 0) {
                $entries[] = $this->postSplitLeg($trade, $sellerShare, $this->sellerAccountCode($trade), 'settle-seller');
            }

            if (bccomp($buyerShare, '0', 4) > 0) {
                $entries[] = $this->postSplitLeg($trade, $buyerShare, $this->buyerAccountCode($trade), 'settle-buyer');
            }

            foreach ($entries as $entry) {
                $this->rememberEntry($trade, $entry);
            }

            return $entries;
        }

        $entry = $this->ledger->postIdempotent(
            "safedeal-settle:{$trade->trade_id}",
            "SafeDeal settlement {$trade->trade_id}",
            [
                // Escrow holding released (liability, debit), seller balance
                // increases (liability, credit).
                ['account_code' => $this->escrowAccountCode($trade), 'debit' => $amount, 'credit' => '0.0000', 'currency' => $trade->currency, 'narrative' => "SafeDeal settlement {$trade->trade_id}"],
                ['account_code' => $this->sellerAccountCode($trade), 'debit' => '0.0000', 'credit' => $amount, 'currency' => $trade->currency, 'narrative' => "SafeDeal settlement {$trade->trade_id}"],
            ],
            'SafeDeal',
            $trade->trade_id,
        );

        $this->rememberEntry($trade, $entry);

        return [$entry];
    }

    private function postSplitLeg(SafeDealTrade $trade, string $share, string $toAccount, string $leg): JournalEntry
    {
        return $this->ledger->postIdempotent(
            "safedeal-{$leg}:{$trade->trade_id}",
            "SafeDeal split settlement {$trade->trade_id} ({$leg})",
            [
                ['account_code' => $this->escrowAccountCode($trade), 'debit' => $share, 'credit' => '0.0000', 'currency' => $trade->currency, 'narrative' => "SafeDeal split {$trade->trade_id}"],
                ['account_code' => $toAccount, 'debit' => '0.0000', 'credit' => $share, 'currency' => $trade->currency, 'narrative' => "SafeDeal split {$trade->trade_id}"],
            ],
            'SafeDeal',
            $trade->trade_id,
        );
    }

    /**
     * Refund / reversal: reverses the funding entry (mirror entry via
     * LedgerService::reverse). Safe to call only after a funding entry
     * exists; returns null when nothing was funded.
     */
    public function refund(SafeDealTrade $trade, string $reason): ?JournalEntry
    {
        $fundingEntryId = $trade->metadata['funding_entry_id'] ?? null;

        if ($fundingEntryId === null) {
            return null;
        }

        $funding = JournalEntry::find($fundingEntryId);

        if ($funding === null || $funding->status->value !== 'posted') {
            return null;
        }

        $reversal = $this->ledger->reverse($funding->id, "SafeDeal refund {$trade->trade_id}: {$reason}");

        $this->rememberEntry($trade, $reversal);

        return $reversal;
    }

    private function rememberEntry(SafeDealTrade $trade, JournalEntry $entry): void
    {
        $metadata = $trade->metadata ?? [];
        $ids = (array) ($metadata['ledger_entry_ids'] ?? []);

        if (! in_array($entry->id, $ids, true)) {
            $ids[] = $entry->id;
        }

        $metadata['ledger_entry_ids'] = $ids;

        if (! isset($metadata['funding_entry_id']) && str_starts_with((string) $entry->description, 'SafeDeal funding')) {
            $metadata['funding_entry_id'] = $entry->id;
        }

        $trade->metadata = $metadata;
        $trade->save();
    }
}
