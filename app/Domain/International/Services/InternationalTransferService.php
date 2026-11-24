<?php

declare(strict_types=1);

namespace App\Domain\International\Services;

use App\Domain\International\Contracts\InternationalRailGatewayInterface;
use App\Domain\International\Enums\ChargeBearer;
use App\Domain\International\Enums\InternationalRail;
use App\Domain\International\Enums\InternationalTransferStatus;
use App\Domain\International\Enums\ScreeningOutcome;
use App\Domain\International\Enums\SettlementInstructionStatus;
use App\Domain\International\Enums\TransferPurpose;
use App\Domain\International\Exceptions\ExchangeRateUnavailableException;
use App\Domain\International\Exceptions\InternationalRailNotAvailableException;
use App\Domain\International\Exceptions\InternationalTransferException;
use App\Domain\International\Exceptions\InvalidTransferTransitionException;
use App\Domain\International\Models\Beneficiary;
use App\Domain\International\Models\Counterparty;
use App\Domain\International\Models\InternationalAccount;
use App\Domain\International\Models\InternationalTransfer;
use App\Domain\International\Models\InternationalTransferStatusHistory;
use App\Domain\International\Models\SettlementInstruction;
use App\Domain\International\ValueObjects\InternationalReference;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\EntryStatus;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Ledger\ValueObjects\DecimalMath;
use App\Domain\Ledger\ValueObjects\MoneyAmount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * International P2P transfer workflow.
 *
 * State machine (enforced under a row lock, every step audited):
 *   draft → submitted → screening → approved → processing → settled
 *                                  ↘ rejected
 *   approved → reversed · processing → failed | reversed
 *
 * Money rules:
 * - Settlement goes ONLY through LedgerService (postGuarded for the
 *   guarded sender account, postIdempotent for the settlement leg).
 *   No float, no direct balance mutation, no invented provider.
 * - process() reserves the funds internally: debit sender account,
 *   credit the `intl:clearing:{CCY}` clearing account (a named
 *   liability, not a float — the funds sit in a balanced ledger entry
 *   linked to the transfer). The fee is a SEPARATE entry (debit sender,
 *   credit `intl:fees:{CCY}`).
 * - External dispatch always fails closed (DisabledInternationalRailGateway):
 *   attemptExternalDispatch() reverses both legs and fails the transfer,
 *   returning the funds to the sender. No money ever leaves the ledger
 *   without a provider.
 * - settle() is an OPERATOR recording of an external confirmation
 *   (external reference required): debit clearing, credit
 *   `intl:settled:{CCY}`. It records a settlement that happened outside
 *   this system; it does not execute one.
 * - Reversal uses LedgerService::reverse() (mirror entries); original
 *   entries are never edited.
 */
final class InternationalTransferService
{
    private InternationalRailGatewayInterface $gateway;

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly TransferFeeService $fees,
        private readonly QuotationService $quotations,
        private readonly InternationalScreeningService $screening,
        private readonly InternationalAuditService $audit,
        private readonly InternationalAccountService $accounts,
    ) {
        // No container binding exists for the gateway (no provider is
        // registered), so the fail-closed disabled gateway is the
        // default. A future provider adapter is injected via
        // setGateway() — or via InternationalServiceProvider once it is
        // registered in bootstrap/providers.php.
        $this->gateway = new DisabledInternationalRailGateway();
    }

    public function setGateway(InternationalRailGatewayInterface $gateway): void
    {
        $this->gateway = $gateway;
    }

    // ------------------------------------------------------------------
    // Creation (idempotent)
    // ------------------------------------------------------------------

    /**
     * Create a transfer in `draft`. The idempotency key is mandatory:
     * a replay with the same key + same payload returns the original
     * transfer; a replay with a different payload is a 409 conflict.
     *
     * @param array{
     *     international_account_id: string,
     *     counterparty_id: string,
     *     beneficiary_id: string,
     *     amount_minor: int,
     *     currency: string,
     *     destination_currency: string,
     *     purpose: string,
     *     purpose_detail?: string|null,
     *     rail: string,
     *     exchange_quotation_id?: string|null,
     *     charge_bearer?: string|null,
     *     value_date?: string|null,
     *     remittance_information?: string|null,
     * } $data
     */
    public function createTransfer(User $user, array $data, string $idempotencyKey): InternationalTransfer
    {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('An idempotency key is required to create an international transfer.');
        }

        $payloadHash = $this->payloadHash($data);

        $existing = InternationalTransfer::where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $this->assertOwner($existing, $user);

            if ($existing->payload_hash !== $payloadHash) {
                throw new InternationalTransferException(
                    "Idempotency key '{$idempotencyKey}' was already used with a different payload."
                );
            }

            return $existing;
        }

        $account = InternationalAccount::find($data['international_account_id'] ?? '');
        if ($account === null || (int) $account->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('International account not found for this user.');
        }
        if (! $account->isActive()) {
            throw new InvalidArgumentException('International account is not active.');
        }

        $counterparty = Counterparty::find($data['counterparty_id'] ?? '');
        if ($counterparty === null || (int) $counterparty->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('Counterparty not found for this user.');
        }

        $beneficiary = Beneficiary::find($data['beneficiary_id'] ?? '');
        if ($beneficiary === null || (int) $beneficiary->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('Beneficiary not found for this user.');
        }
        if ($beneficiary->counterparty_id !== $counterparty->id) {
            throw new InvalidArgumentException('Beneficiary does not belong to the given counterparty.');
        }
        if (! $beneficiary->is_active) {
            throw new InvalidArgumentException('Beneficiary is not active.');
        }

        $amountMinor = $data['amount_minor'] ?? null;
        if (! is_int($amountMinor) || $amountMinor <= 0) {
            throw new InvalidArgumentException('Amount must be a positive integer in minor units.');
        }

        $currency = strtoupper((string) ($data['currency'] ?? ''));
        $destinationCurrency = strtoupper((string) ($data['destination_currency'] ?? ''));

        if (
            ! InternationalAccountService::isSupportedCurrency($currency)
            || ! InternationalAccountService::isSupportedCurrency($destinationCurrency)
        ) {
            throw new InvalidArgumentException('Currencies must be supported international currencies (ISO-4217).');
        }
        if ($currency !== $account->currency) {
            throw new InvalidArgumentException('Transfer currency must match the source account currency.');
        }
        if ($beneficiary->currency !== $destinationCurrency) {
            throw new InvalidArgumentException('Beneficiary currency must match the destination currency.');
        }

        $purpose = TransferPurpose::tryFrom((string) ($data['purpose'] ?? ''));
        if ($purpose === null) {
            throw new InvalidArgumentException('Unknown transfer purpose.');
        }
        $purposeDetail = $data['purpose_detail'] ?? null;
        if ($purpose->requiresDetail() && trim((string) $purposeDetail) === '') {
            throw new InvalidArgumentException('A purpose detail is required when the purpose is "other".');
        }

        $rail = InternationalRail::tryFrom((string) ($data['rail'] ?? ''));
        if ($rail === null) {
            throw new InvalidArgumentException('Unknown international rail.');
        }

        // Cross-currency transfers need an accepted, unexpired quotation
        // that matches this exact amount and pair. Same-currency
        // transfers need none.
        $quotationId = $data['exchange_quotation_id'] ?? null;
        if ($destinationCurrency !== $currency) {
            if (! is_string($quotationId) || $quotationId === '') {
                throw new ExchangeRateUnavailableException(
                    'A cross-currency transfer requires an accepted exchange quotation.'
                );
            }
            $quotation = $this->quotations->usableQuotationFor(
                $user,
                $quotationId,
                $currency,
                $destinationCurrency,
                $amountMinor
            );
            $destinationAmountMinor = (int) $quotation->destination_amount_minor;
        } else {
            if (is_string($quotationId) && $quotationId !== '') {
                throw new InvalidArgumentException('A same-currency transfer must not reference a quotation.');
            }
            $destinationAmountMinor = $amountMinor;
        }

        $fee = $this->fees->calculate($amountMinor, $currency);

        $chargeBearer = ChargeBearer::tryFrom((string) ($data['charge_bearer'] ?? 'sha')) ?? ChargeBearer::SHA;

        try {
            return DB::transaction(function () use (
                $user,
                $data,
                $idempotencyKey,
                $payloadHash,
                $account,
                $counterparty,
                $beneficiary,
                $amountMinor,
                $currency,
                $destinationCurrency,
                $destinationAmountMinor,
                $purpose,
                $purposeDetail,
                $rail,
                $quotationId,
                $fee,
                $chargeBearer,
            ): InternationalTransfer {
                $transfer = InternationalTransfer::create([
                    'user_id'                  => $user->id,
                    'international_account_id' => $account->id,
                    'counterparty_id'          => $counterparty->id,
                    'beneficiary_id'           => $beneficiary->id,
                    'exchange_quotation_id'    => $quotationId,
                    'reference'                => InternationalReference::generate(),
                    'end_to_end_reference'     => InternationalReference::generateEndToEnd(),
                    'external_reference'       => null,
                    'idempotency_key'          => $idempotencyKey,
                    'payload_hash'             => $payloadHash,
                    'amount_minor'             => $amountMinor,
                    'fee_minor'                => (int) $fee['fee_minor'],
                    'currency'                 => $currency,
                    'destination_currency'     => $destinationCurrency,
                    'destination_amount_minor' => $destinationAmountMinor,
                    'purpose'                  => $purpose,
                    'purpose_detail'           => $purposeDetail,
                    'rail'                     => $rail,
                    'status'                   => InternationalTransferStatus::DRAFT,
                    'screening_status'         => 'not_screened',
                    'metadata'                 => [
                        'charge_bearer'          => $chargeBearer->value,
                        'value_date'             => $data['value_date'] ?? null,
                        'remittance_information' => $data['remittance_information'] ?? null,
                    ],
                ]);

                InternationalTransferStatusHistory::create([
                    'international_transfer_id' => $transfer->id,
                    'from_status'               => null,
                    'to_status'                 => InternationalTransferStatus::DRAFT->value,
                    'actor_user_id'             => (int) $user->id,
                    'reason'                    => 'created',
                ]);

                $this->audit->log($transfer->id, (int) $user->id, 'transfer.created', [
                    'reference'    => $transfer->reference,
                    'amount_minor' => $amountMinor,
                    'currency'     => $currency,
                    'rail'         => $rail->value,
                ]);

                return $transfer;
            });
        } catch (QueryException $e) {
            // Lost a race on the unique idempotency key: replay semantics.
            $existing = InternationalTransfer::where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null && $existing->payload_hash === $payloadHash) {
                return $existing;
            }

            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Workflow
    // ------------------------------------------------------------------

    public function submit(InternationalTransfer $transfer, User $user): InternationalTransfer
    {
        $this->assertOwner($transfer, $user);

        return $this->transition(
            $transfer,
            InternationalTransferStatus::SUBMITTED,
            (int) $user->id,
            'submitted by owner'
        );
    }

    /**
     * Move submitted → screening and run the screening check.
     * HIT → rejected, CLEAR → approved, manual/error → stays in
     * screening for a compliance-officer decision.
     */
    public function beginScreening(InternationalTransfer $transfer, User $operator): InternationalTransfer
    {
        $transfer = $this->transition(
            $transfer,
            InternationalTransferStatus::SCREENING,
            (int) $operator->id,
            'screening started'
        );

        $result = $this->screening->screen($transfer->refresh());

        if ($result->outcome === ScreeningOutcome::HIT) {
            return $this->transition(
                $transfer->refresh(),
                InternationalTransferStatus::REJECTED,
                (int) $operator->id,
                'screening hit: ' . $result->detail,
            );
        }

        if ($result->outcome === ScreeningOutcome::CLEAR) {
            return $this->transition(
                $transfer->refresh(),
                InternationalTransferStatus::APPROVED,
                (int) $operator->id,
                'screening clear via ' . $result->provider,
            );
        }

        // MANUAL_REVIEW_REQUIRED / ERROR: stays in screening.
        $this->audit->log($transfer->id, (int) $operator->id, 'transfer.screening_pending', [
            'outcome'  => $result->outcome->value,
            'provider' => $result->provider,
        ]);

        return $transfer->refresh();
    }

    /** Compliance-officer decision while a transfer is in screening. */
    public function recordScreeningDecision(
        InternationalTransfer $transfer,
        User $operator,
        string $decision,
        ?string $reason = null,
    ): InternationalTransfer {
        if (! in_array($decision, ['clear', 'reject'], true)) {
            throw new InvalidArgumentException("Screening decision must be 'clear' or 'reject'.");
        }

        if ($decision === 'reject' && trim((string) $reason) === '') {
            throw new InvalidArgumentException('A reason is required to reject a transfer at screening.');
        }

        $target = $decision === 'clear'
            ? InternationalTransferStatus::APPROVED
            : InternationalTransferStatus::REJECTED;

        $transfer->update([
            'screening_status' => $decision === 'clear' ? 'clear' : 'hit',
            'screening_notes'  => $reason,
        ]);

        return $this->transition($transfer->refresh(), $target, (int) $operator->id, $reason ?? "screening {$decision}");
    }

    /**
     * Reserve the funds: approved → processing.
     *
     * Posts (via LedgerService::postGuarded, so the sender account can
     * never go negative) the fee entry first and the principal entry
     * second, then flips the status — all in one DB transaction, so a
     * failed posting leaves the transfer approved with no legs. A
     * retry after a partial post reuses the stored entry ids instead of
     * posting again.
     */
    public function process(InternationalTransfer $transfer, User $user): InternationalTransfer
    {
        $this->assertOwner($transfer, $user);

        return DB::transaction(function () use ($transfer, $user): InternationalTransfer {
            /** @var InternationalTransfer $locked */
            $locked = InternationalTransfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InternationalTransferStatus::APPROVED) {
                throw new InvalidTransferTransitionException(
                    "Only an approved transfer can be processed (status: {$locked->status->value})."
                );
            }

            $account = $locked->account;
            $currency = $locked->currency;
            $clearingCode = $this->clearingAccountCode($currency);
            $feesCode = $this->feesAccountCode($currency);

            if ($locked->journal_entry_id === null) {
                if ((int) $locked->fee_minor > 0) {
                    $feeEntry = $this->ledger->postGuarded(
                        "International transfer fee {$locked->reference}",
                        [
                            ['account_code' => $account->ledger_account_code, 'debit' => $this->decimal((int) $locked->fee_minor, $currency), 'credit' => '0.0000', 'currency' => $currency, 'narrative' => 'international fee'],
                            ['account_code' => $feesCode, 'debit' => '0.0000', 'credit' => $this->decimal((int) $locked->fee_minor, $currency), 'currency' => $currency, 'narrative' => 'international fee income'],
                        ],
                        [$account->ledger_account_code],
                        'international',
                        $locked->id,
                    );
                    $locked->fee_journal_entry_id = $feeEntry->id;
                }

                $entry = $this->ledger->postGuarded(
                    "International transfer {$locked->reference}",
                    [
                        ['account_code' => $account->ledger_account_code, 'debit' => $this->decimal((int) $locked->amount_minor, $currency), 'credit' => '0.0000', 'currency' => $currency, 'narrative' => 'international principal'],
                        ['account_code' => $clearingCode, 'debit' => '0.0000', 'credit' => $this->decimal((int) $locked->amount_minor, $currency), 'currency' => $currency, 'narrative' => 'international clearing'],
                    ],
                    [$account->ledger_account_code],
                    'international',
                    $locked->id,
                );
                $locked->journal_entry_id = $entry->id;
                $locked->save();
            }

            $metadata = $locked->metadata ?? [];
            SettlementInstruction::firstOrCreate(
                ['international_transfer_id' => $locked->id],
                [
                    'rail'                   => $locked->rail,
                    'charge_bearer'          => ChargeBearer::tryFrom((string) ($metadata['charge_bearer'] ?? 'sha')) ?? ChargeBearer::SHA,
                    'value_date'             => $metadata['value_date'] ?? null,
                    'remittance_information' => $metadata['remittance_information'] ?? null,
                    'status'                 => SettlementInstructionStatus::PENDING,
                ],
            );

            return $this->transition($locked, InternationalTransferStatus::PROCESSING, (int) $user->id, 'funds reserved in clearing');
        });
    }

    /**
     * Try to dispatch the transfer to its rail. Today this ALWAYS fails
     * closed: the gateway refuses every rail, the legs are reversed
     * (funds return to the sender), the transfer is marked failed, and
     * the exception is rethrown so the caller reports 422
     * RAIL_NOT_AVAILABLE. A disabled route provably cannot execute.
     */
    public function attemptExternalDispatch(InternationalTransfer $transfer, User $operator): never
    {
        $fresh = $transfer->refresh();

        if ($fresh->status !== InternationalTransferStatus::PROCESSING) {
            throw new InvalidTransferTransitionException(
                "Only a processing transfer can be dispatched (status: {$fresh->status->value})."
            );
        }

        $instruction = $fresh->settlementInstructions()->first();

        try {
            $this->gateway->dispatch($fresh->id, $fresh->rail, [
                'reference'      => $fresh->reference,
                'amount_minor'   => $fresh->amount_minor,
                'currency'       => $fresh->currency,
                'instruction_id' => $instruction?->id,
            ]);
        } catch (InternationalRailNotAvailableException $e) {
            $this->failTransfer($fresh, $e->getMessage(), (int) $operator->id);

            if ($instruction !== null) {
                $instruction->update([
                    'status'         => SettlementInstructionStatus::BLOCKED,
                    'failure_reason' => $e->getMessage(),
                ]);
            }

            throw $e;
        }

        // Unreachable with the disabled gateway; kept so a future
        // provider adapter has a defined success path to fill in.
        throw new InternationalTransferException('Unexpected dispatch success without a provider reference.');
    }

    /**
     * Record an external settlement confirmation (operator only).
     *
     * The money movement outside this system is NOT performed here:
     * the operator supplies the external provider reference proving the
     * funds were remitted, and the internal legs move clearing →
     * settled. The clearing balance is pre-checked so settlement can
     * never exceed the reserved funds.
     */
    public function settle(
        InternationalTransfer $transfer,
        User $operator,
        string $externalReference,
    ): InternationalTransfer {
        if (trim($externalReference) === '') {
            throw new InvalidArgumentException('An external settlement reference is required to settle a transfer.');
        }

        return DB::transaction(function () use ($transfer, $operator, $externalReference): InternationalTransfer {
            /** @var InternationalTransfer $locked */
            $locked = InternationalTransfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InternationalTransferStatus::PROCESSING) {
                throw new InvalidTransferTransitionException(
                    "Only a processing transfer can be settled (status: {$locked->status->value})."
                );
            }

            if ($locked->settlement_journal_entry_id !== null) {
                throw new InvalidTransferTransitionException('Transfer already has a settlement entry.');
            }

            $currency = $locked->currency;
            $clearingCode = $this->clearingAccountCode($currency);
            $settledCode = $this->settledAccountCode($currency);
            $principal = $this->decimal((int) $locked->amount_minor, $currency);

            $totals = $this->accounts->postedTotals($clearingCode);
            $clearingCreditBalance = DecimalMath::sub($totals['credit'], $totals['debit'], 4);
            if (DecimalMath::cmp($clearingCreditBalance, $principal, 4) < 0) {
                throw new InternationalTransferException(
                    "Clearing account {$clearingCode} holds {$clearingCreditBalance} {$currency}, less than the {$principal} {$currency} to settle."
                );
            }

            $entry = $this->ledger->postIdempotent(
                "{$locked->idempotency_key}:settle",
                "International settlement {$locked->reference}",
                [
                    ['account_code' => $clearingCode, 'debit' => $principal, 'credit' => '0.0000', 'currency' => $currency, 'narrative' => 'international settlement out of clearing'],
                    ['account_code' => $settledCode, 'debit' => '0.0000', 'credit' => $principal, 'currency' => $currency, 'narrative' => 'international settled'],
                ],
                'international',
                $locked->id,
            );

            $locked->settlement_journal_entry_id = $entry->id;
            $locked->external_reference = $externalReference;
            $locked->save();

            $locked->settlementInstructions()->update([
                'status'             => SettlementInstructionStatus::EXECUTED,
                'external_reference' => $externalReference,
            ]);

            return $this->transition(
                $locked,
                InternationalTransferStatus::SETTLED,
                (int) $operator->id,
                "settled against external reference {$externalReference}"
            );
        });
    }

    /**
     * Reverse a transfer, returning the reserved funds via mirror
     * ledger entries. Allowed from `approved` (no legs yet) and from
     * `processing` (legs reversed). Settled / failed / rejected are
     * terminal and cannot be reversed in place.
     */
    public function reverse(
        InternationalTransfer $transfer,
        User $actor,
        string $reason,
    ): InternationalTransfer {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reversal reason is required.');
        }

        return DB::transaction(function () use ($transfer, $actor, $reason): InternationalTransfer {
            /** @var InternationalTransfer $locked */
            $locked = InternationalTransfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();

            if (
                ! in_array($locked->status, [
                InternationalTransferStatus::APPROVED,
                InternationalTransferStatus::PROCESSING,
                ], true)
            ) {
                throw new InvalidTransferTransitionException(
                    "Only an approved or processing transfer can be reversed (status: {$locked->status->value})."
                );
            }

            $this->reversePostedLegs($locked, $reason);

            $locked->settlementInstructions()->update(['status' => SettlementInstructionStatus::CANCELLED]);

            return $this->transition($locked, InternationalTransferStatus::REVERSED, (int) $actor->id, $reason);
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function transition(
        InternationalTransfer $transfer,
        InternationalTransferStatus $to,
        int $actorUserId,
        string $reason,
    ): InternationalTransfer {
        $from = $transfer->status;

        if (! $from->canTransitionTo($to)) {
            throw new InvalidTransferTransitionException(
                "Transfer cannot move from '{$from->value}' to '{$to->value}'."
            );
        }

        $transfer->update(['status' => $to]);

        InternationalTransferStatusHistory::create([
            'international_transfer_id' => $transfer->id,
            'from_status'               => $from->value,
            'to_status'                 => $to->value,
            'actor_user_id'             => $actorUserId,
            'reason'                    => $reason,
        ]);

        $this->audit->log($transfer->id, $actorUserId, "transfer.{$to->value}", [
            'from'   => $from->value,
            'reason' => $reason,
        ]);

        return $transfer->refresh();
    }

    /** Reverse posted legs (principal + fee) and fail the transfer. */
    private function failTransfer(InternationalTransfer $transfer, string $reason, int $actorUserId): InternationalTransfer
    {
        return DB::transaction(function () use ($transfer, $reason, $actorUserId): InternationalTransfer {
            /** @var InternationalTransfer $locked */
            $locked = InternationalTransfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InternationalTransferStatus::PROCESSING) {
                throw new InvalidTransferTransitionException(
                    "Only a processing transfer can fail (status: {$locked->status->value})."
                );
            }

            $this->reversePostedLegs($locked, 'dispatch failed: ' . $reason);

            $locked->update(['failure_reason' => mb_substr($reason, 0, 2000)]);

            return $this->transition($locked->refresh(), InternationalTransferStatus::FAILED, $actorUserId, $reason);
        });
    }

    /** Mirror-reverse every posted leg of the transfer, if still posted. */
    private function reversePostedLegs(InternationalTransfer $transfer, string $reason): void
    {
        foreach ([$transfer->journal_entry_id, $transfer->fee_journal_entry_id] as $entryId) {
            if ($entryId === null) {
                continue;
            }

            $entry = JournalEntry::find($entryId);

            if ($entry !== null && $entry->status === EntryStatus::POSTED) {
                $this->ledger->reverse($entryId, $reason);
            }
        }
    }

    private function assertOwner(InternationalTransfer $transfer, User $user): void
    {
        if ((int) $transfer->user_id !== (int) $user->id) {
            throw new AuthorizationException('Transfer does not belong to this user.');
        }
    }

    /** @param array<string, mixed> $data */
    private function payloadHash(array $data): string
    {
        $canonical = [
            'international_account_id' => $data['international_account_id'] ?? null,
            'counterparty_id'          => $data['counterparty_id'] ?? null,
            'beneficiary_id'           => $data['beneficiary_id'] ?? null,
            'amount_minor'             => $data['amount_minor'] ?? null,
            'currency'                 => strtoupper((string) ($data['currency'] ?? '')),
            'destination_currency'     => strtoupper((string) ($data['destination_currency'] ?? '')),
            'purpose'                  => $data['purpose'] ?? null,
            'rail'                     => $data['rail'] ?? null,
            'exchange_quotation_id'    => $data['exchange_quotation_id'] ?? null,
        ];

        return hash('sha256', (string) json_encode($canonical));
    }

    private function decimal(int $minor, string $currency): string
    {
        return MoneyAmount::fromMinorUnits($minor, $currency)->decimal;
    }

    private function clearingAccountCode(string $currency): string
    {
        return $this->ensureLedgerAccount("intl:clearing:{$currency}", "International clearing {$currency}", AccountType::LIABILITY, $currency);
    }

    private function feesAccountCode(string $currency): string
    {
        return $this->ensureLedgerAccount("intl:fees:{$currency}", "International fees {$currency}", AccountType::REVENUE, $currency);
    }

    private function settledAccountCode(string $currency): string
    {
        return $this->ensureLedgerAccount("intl:settled:{$currency}", "International settled {$currency}", AccountType::LIABILITY, $currency);
    }

    private function ensureLedgerAccount(string $code, string $name, AccountType $type, string $currency): string
    {
        LedgerAccount::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'type' => $type, 'currency' => $currency],
        );

        return $code;
    }
}
