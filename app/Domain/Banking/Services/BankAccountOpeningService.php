<?php

declare(strict_types=1);

namespace App\Domain\Banking\Services;

use App\Domain\Banking\Contracts\AccountProviderInterface;
use App\Domain\Banking\Exceptions\AccountOpeningException;
use App\Domain\Banking\Exceptions\BankOperationException;
use App\Domain\Banking\Models\BankAccountModel;
use App\Domain\Banking\Models\BankBalance;
use App\Domain\Banking\Models\BankStatement;
use App\Domain\Banking\Models\ExternalBankAccountApplication;
use App\Domain\Compliance\Services\KycService;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * External bank-account onboarding workflow:
 *
 *   request → account-type selection → KYC eligibility check (Compliance
 *   owns the verdict — this service only reads KycService::isKycApproved)
 *   → submission via the configured AccountProvider adapter → status
 *   tracking (pending / rejected / approved) → account number + IBAN
 *   retrieval ONLY from the provider response → balance / statement
 *   retrieval via the provider → status sync → closure (capability-gated).
 *
 * No account number or IBAN is ever generated here. A provider rejection is
 * recorded on the application and surfaced as an exception — never a fake
 * approval. An unclassifiable provider outcome (timeout, ambiguous
 * response) leaves the application in `requires_manual_review`.
 */
class BankAccountOpeningService
{
    public function __construct(
        private readonly AccountProviderInterface $provider,
        private readonly KycService $kyc,
    ) {
    }

    /**
     * @param  array<string, mixed>  $details  account_type, currency,
     *                                          holder_name, holder_address?,
     *                                          sandbox_outcome? (sandbox only)
     */
    public function requestOpening(
        User $user,
        array $details,
        ?string $idempotencyKey = null,
    ): ExternalBankAccountApplication {
        $idempotencyKey ??= (string) Str::uuid();
        $providerName = $this->provider->getName();

        // Idempotency: the same key returns the same application, never a
        // second submission to the provider.
        $existing = ExternalBankAccountApplication::query()
            ->where('user_uuid', $user->uuid)
            ->where('provider', $providerName)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing instanceof ExternalBankAccountApplication) {
            return $existing;
        }

        $accountType = (string) ($details['account_type'] ?? '');
        $currency = strtoupper((string) ($details['currency'] ?? ''));
        $allowedTypes = (array) config('banking.account_types', ['current', 'savings', 'business']);
        $allowedCurrencies = (array) config('banking.currencies', ['EUR', 'USD', 'GBP']);
        if (! in_array($accountType, $allowedTypes, true)) {
            throw new InvalidArgumentException("Unsupported account type: {$accountType}.");
        }
        if (! in_array($currency, $allowedCurrencies, true)) {
            throw new InvalidArgumentException("Unsupported currency: {$currency}.");
        }

        // KYC eligibility — Compliance owns the verdict; never duplicated here.
        if ((bool) config('banking.kyc_gate', true) && ! $this->kyc->isKycApproved($user)) {
            throw new AccountOpeningException(
                "Bank account opening requires approved KYC (current status: {$this->kyc->getKycStatus($user)}).",
            );
        }

        $application = new ExternalBankAccountApplication();
        $application->user_uuid = $user->uuid;
        $application->bank_code = $this->provider instanceof \App\Domain\Banking\Contracts\IBankConnector
            ? $this->provider->getBankCode()
            : strtoupper($providerName);
        $application->provider = $providerName;
        $application->account_type = $accountType;
        $application->currency = $currency;
        $application->status = ExternalBankAccountApplication::STATUS_PENDING;
        $application->idempotency_key = $idempotencyKey;
        $application->metadata = ['holder_name' => (string) ($details['holder_name'] ?? '')];
        $application->save();

        Event::dispatch(new \App\Domain\Banking\Events\BankAccountOpeningRequested(
            (string) $application->id, (string) $user->uuid, $providerName, $idempotencyKey,
        ));

        $providerDetails = array_merge($details, [
            'account_type' => $accountType,
            'currency'     => $currency,
            'holder_name'  => (string) ($details['holder_name'] ?? $user->name ?? ''),
            'user_id'      => (string) $user->uuid,
        ]);

        try {
            $bankAccount = $this->provider->openAccount((string) $user->uuid, $providerDetails, $idempotencyKey);
        } catch (BankOperationException $e) {
            // Definitive provider rejection — record it, never fake approval.
            $application->status = ExternalBankAccountApplication::STATUS_REJECTED;
            $application->rejection_reason = $e->getMessage();
            $application->save();

            Event::dispatch(new \App\Domain\Banking\Events\BankAccountOpeningRejected(
                (string) $application->id, (string) $user->uuid, $e->getMessage(),
            ));

            throw new AccountOpeningException("Bank rejected the application: {$e->getMessage()}", previous: $e);
        } catch (Throwable $e) {
            // Timeout / ambiguous outcome: unknown, not failed. Manual review.
            $application->status = ExternalBankAccountApplication::STATUS_REQUIRES_MANUAL_REVIEW;
            $application->rejection_reason = 'Unknown provider outcome: '.$e->getMessage();
            $application->save();

            Event::dispatch(new \App\Domain\Banking\Events\BankAccountOpeningRequiresManualReview(
                (string) $application->id, (string) $user->uuid, $e->getMessage(),
            ));

            throw new AccountOpeningException(
                "Bank account opening outcome is unknown (provider error) — application is held for manual review.",
                previous: $e,
            );
        }

        $this->applyProviderOutcome($user, $application, $bankAccount);

        return $application->fresh() ?? $application;
    }

    /**
     * Apply a provider openAccount() outcome to an application. The
     * provider's response decides — never assume approval. 'active' →
     * persist the account (number/IBAN from the response only) and approve;
     * 'pending' → keep pending with the provider reference stored, for
     * syncStatus() to pick up; anything else → manual review, never a fake
     * approval.
     */
    private function applyProviderOutcome(
        User $user,
        ExternalBankAccountApplication $application,
        \App\Domain\Banking\Models\BankAccount $bankAccount,
    ): void {
        if ($bankAccount->isActive()) {
            $this->persistOpenedAccount($user, $application, $bankAccount);

            return;
        }

        if ($bankAccount->status === 'pending') {
            $application->external_id = $bankAccount->id;
            $application->status = ExternalBankAccountApplication::STATUS_PENDING;
            $application->save();

            return;
        }

        $application->external_id = $bankAccount->id;
        $application->status = ExternalBankAccountApplication::STATUS_REQUIRES_MANUAL_REVIEW;
        $application->rejection_reason = "Provider returned unclassifiable account status: {$bankAccount->status}.";
        $application->save();

        Event::dispatch(new \App\Domain\Banking\Events\BankAccountOpeningRequiresManualReview(
            (string) $application->id, (string) $user->uuid, "Provider status: {$bankAccount->status}.",
        ));
    }

    /**
     * Re-query the provider for the application's current status. When the
     * provider reports approved and no bank account row exists yet, the
     * account is fetched from the provider and persisted.
     */
    public function syncStatus(string $applicationId): ExternalBankAccountApplication
    {
        $application = $this->applicationOrFail($applicationId);
        if ($application->external_id === null) {
            return $application;
        }

        $status = $this->provider->getAccountStatus($application->external_id);
        $mapped = match ($status['status'] ?? 'unknown') {
            'approved', 'active' => ExternalBankAccountApplication::STATUS_APPROVED,
            'pending' => ExternalBankAccountApplication::STATUS_PENDING,
            'rejected' => ExternalBankAccountApplication::STATUS_REJECTED,
            'closed' => ExternalBankAccountApplication::STATUS_CLOSED,
            default => ExternalBankAccountApplication::STATUS_REQUIRES_MANUAL_REVIEW,
        };

        if ($mapped === ExternalBankAccountApplication::STATUS_APPROVED) {
            $user = User::query()->where('uuid', $application->user_uuid)->first();
            if ($user instanceof User) {
                $bankAccount = $this->provider->getAccount($application->external_id);
                $this->persistOpenedAccount($user, $application, $bankAccount);
            }
        } else {
            $application->status = $mapped;
            $application->save();
        }

        return $application->fresh() ?? $application;
    }

    /**
     * Balance / statement retrieval — always via the provider.
     */
    public function getBalance(string $applicationId, ?string $currency = null): BankBalance
    {
        $application = $this->applicationOrFail($applicationId);
        if ($application->external_id === null) {
            throw new AccountOpeningException('No provider account exists for this application yet.');
        }

        return $this->provider->getBalance($application->external_id, $currency ?? $application->currency);
    }

    public function getStatement(string $applicationId, \DateTime $from, \DateTime $to, string $format = 'JSON'): BankStatement
    {
        $application = $this->applicationOrFail($applicationId);
        if ($application->external_id === null) {
            throw new AccountOpeningException('No provider account exists for this application yet.');
        }
        if (! $this->provider instanceof \App\Domain\Banking\Contracts\IBankConnector) {
            throw new AccountOpeningException("Provider {$this->provider->getName()} does not expose statements.");
        }

        return $this->provider->getStatement($application->external_id, $from, $to, $format);
    }

    /**
     * Closure workflow — capability-gated. Providers that do not support
     * closure fail with an explicit error instead of pretending to close.
     */
    public function requestClosure(string $applicationId, string $reason): ExternalBankAccountApplication
    {
        $application = $this->applicationOrFail($applicationId);
        if ($application->external_id === null) {
            throw new AccountOpeningException('No provider account exists for this application yet.');
        }
        if (! $this->provider->getCapabilities()->hasFeature('account_closure')) {
            throw new AccountOpeningException(
                "Provider {$this->provider->getName()} does not support account closure (capability account_closure=false).",
            );
        }

        $this->provider->closeAccount(
            $application->external_id,
            $reason,
            'close:'.(string) $application->id.':'.(string) Str::uuid(),
        );

        $application->status = ExternalBankAccountApplication::STATUS_CLOSED;
        $application->save();

        BankAccountModel::query()
            ->where('bank_code', $application->bank_code)
            ->where('external_id', $application->external_id)
            ->update(['status' => 'closed']);

        Event::dispatch(new \App\Domain\Banking\Events\BankAccountOpeningClosed(
            (string) $application->id, (string) $application->user_uuid, $reason,
        ));

        return $application->fresh() ?? $application;
    }

    // ── Admin hooks ──────────────────────────────────────────────────────

    /**
     * @return EloquentCollection<int, ExternalBankAccountApplication>
     */
    public function listApplications(?string $status = null, int $limit = 50): EloquentCollection
    {
        $query = ExternalBankAccountApplication::query()->orderByDesc('created_at');
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Retry a pending / manual-review application with the SAME idempotency
     * key — the provider must not open a second account for it.
     */
    public function retryOpening(string $applicationId): ExternalBankAccountApplication
    {
        $application = $this->applicationOrFail($applicationId);
        if ($application->status === ExternalBankAccountApplication::STATUS_APPROVED) {
            throw new AccountOpeningException('Application is already approved.');
        }
        if ($application->status === ExternalBankAccountApplication::STATUS_CLOSED) {
            throw new AccountOpeningException('Application is closed and cannot be retried.');
        }

        $user = User::query()->where('uuid', $application->user_uuid)->firstOrFail();

        $application->status = ExternalBankAccountApplication::STATUS_PENDING;
        $application->rejection_reason = null;
        $application->save();

        $details = array_merge(is_array($application->metadata) ? $application->metadata : [], [
            'account_type' => $application->account_type,
            'currency'     => $application->currency,
            'user_id'      => (string) $user->uuid,
        ]);

        try {
            $bankAccount = $this->provider->openAccount(
                (string) $user->uuid, $details, (string) $application->idempotency_key,
            );
        } catch (BankOperationException $e) {
            $application->status = ExternalBankAccountApplication::STATUS_REJECTED;
            $application->rejection_reason = $e->getMessage();
            $application->save();

            throw new AccountOpeningException("Bank rejected the application: {$e->getMessage()}", previous: $e);
        } catch (Throwable $e) {
            $application->status = ExternalBankAccountApplication::STATUS_REQUIRES_MANUAL_REVIEW;
            $application->rejection_reason = 'Unknown provider outcome: '.$e->getMessage();
            $application->save();

            throw new AccountOpeningException(
                'Bank account opening outcome is unknown (provider error) — application is held for manual review.',
                previous: $e,
            );
        }

        $this->applyProviderOutcome($user, $application, $bankAccount);

        return $application->fresh() ?? $application;
    }

    /**
     * Resolve a manual-review application: 'retry' re-submits with the same
     * idempotency key, 'reject' closes it as rejected.
     */
    public function resolveManualReview(string $applicationId, string $resolution, ?string $note = null): ExternalBankAccountApplication
    {
        $application = $this->applicationOrFail($applicationId);

        if ($resolution === 'retry') {
            return $this->retryOpening($applicationId);
        }
        if ($resolution === 'reject') {
            $application->status = ExternalBankAccountApplication::STATUS_REJECTED;
            $application->rejection_reason = $note ?? $application->rejection_reason;
            $application->save();

            return $application;
        }

        throw new InvalidArgumentException("Unknown manual-review resolution: {$resolution} (expected 'retry' or 'reject').");
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function applicationOrFail(string $applicationId): ExternalBankAccountApplication
    {
        $application = ExternalBankAccountApplication::query()->find($applicationId);
        if (! $application instanceof ExternalBankAccountApplication) {
            throw new InvalidArgumentException("Bank account application {$applicationId} not found.");
        }

        return $application;
    }

    /**
     * Persist the opened account. Account number + IBAN are copied ONLY from
     * the provider's response object (encrypted at rest, like
     * BankIntegrationService::createBankAccount) — this method generates
     * nothing.
     */
    private function persistOpenedAccount(
        User $user,
        ExternalBankAccountApplication $application,
        \App\Domain\Banking\Models\BankAccount $bankAccount,
    ): void {
        DB::transaction(function () use ($user, $application, $bankAccount) {
            $existing = BankAccountModel::query()
                ->where('bank_code', $bankAccount->bankCode)
                ->where('external_id', $bankAccount->id)
                ->first();
            $values = [
                'user_uuid'      => $user->uuid,
                'account_number' => encrypt($bankAccount->accountNumber),
                'iban'           => encrypt($bankAccount->iban),
                'swift'          => $bankAccount->swift,
                'currency'       => $bankAccount->currency,
                'account_type'   => $bankAccount->accountType,
                'status'         => 'active',
                'metadata'       => $bankAccount->metadata,
            ];
            if ($existing instanceof BankAccountModel) {
                $existing->update($values);
            } else {
                BankAccountModel::create(array_merge(['id' => (string) Str::uuid()], $values, [
                    'bank_code'   => $bankAccount->bankCode,
                    'external_id' => $bankAccount->id,
                ]));
            }

            $application->external_id = $bankAccount->id;
            $application->status = ExternalBankAccountApplication::STATUS_APPROVED;
            $application->save();
        });

        Event::dispatch(new \App\Domain\Banking\Events\BankAccountOpeningApproved(
            (string) $application->id, (string) $user->uuid, $bankAccount->id,
        ));
    }
}
