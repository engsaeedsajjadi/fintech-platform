<?php

declare(strict_types=1);

namespace App\Domain\Banking\Connectors;

use App\Domain\Banking\Contracts\AccountProviderInterface;
use App\Domain\Banking\Contracts\IBankConnector;
use App\Domain\Banking\Exceptions\AccountNotFoundException;
use App\Domain\Banking\Exceptions\BankOperationException;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankBalance;
use App\Domain\Banking\Models\BankCapabilities;
use App\Domain\Banking\Models\BankStatement;
use App\Domain\Banking\Models\BankTransaction;
use App\Domain\Banking\Models\BankTransfer;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SANDBOX ONLY — simulates a partner bank's account-opening workflow
 * locally for development and tests. It is NOT a real bank, holds no real
 * money, and issues no real account numbers or IBANs: the numbers it
 * returns are clearly-marked test values (bank code SANDBOX, DE test IBAN
 * with a fictional bank code, metadata sandbox=true).
 *
 * The constructor REFUSES to run under APP_ENV=production, and the
 * production provider slot stays empty until a partner bank's official API
 * docs, credentials and approval exist. Nothing in the opening workflow
 * generates numbers locally — the workflow copies them from this adapter's
 * response, exactly as it would from a real provider.
 *
 * Test outcomes (via $accountDetails['sandbox_outcome']):
 * - 'approved' (default): account opens immediately.
 * - 'pending_then_approved': the first status query reports pending, later
 *   queries report approved — exercises the status-tracking path.
 * - 'rejected': openAccount() throws a provider rejection.
 */
class SandboxBankAdapter implements IBankConnector, AccountProviderInterface
{
    public const BANK_CODE = 'SANDBOX';

    /** Fictional bank code used inside sandbox test IBANs. */
    private const SANDBOX_BANK_CODE = '70000000';

    private const CACHE_PREFIX = 'sandbox_bank:account:';

    private const IDEMPOTENCY_PREFIX = 'sandbox_bank:idempotency:';

    private readonly string $webhookSecret;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->webhookSecret = (string) ($config['webhook_secret'] ?? '');
        if (app()->environment('production')) {
            throw new RuntimeException(
                'SandboxBankAdapter must never run in production. Configure a real bank provider (BANK_ACCOUNT_PROVIDER) with official API credentials.',
            );
        }
    }

    public function getName(): string
    {
        return 'sandbox';
    }

    public function getBankCode(): string
    {
        return self::BANK_CODE;
    }

    public function getBankName(): string
    {
        return 'Sandbox Bank (simulated — not a real bank)';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getCapabilities(): BankCapabilities
    {
        return new BankCapabilities(
            supportedCurrencies: ['EUR', 'USD', 'GBP'],
            supportedTransferTypes: [],
            features: ['account_opening', 'account_closure', 'statements', 'sandbox'],
            limits: [],
            fees: [],
            supportsInstantTransfers: false,
            supportsScheduledTransfers: false,
            supportsBulkTransfers: false,
            supportsDirectDebits: false,
            supportsStandingOrders: false,
            supportsVirtualAccounts: false,
            supportsMultiCurrency: true,
            supportsWebhooks: true,
            supportsStatements: true,
            supportsCardIssuance: false,
            maxAccountsPerUser: 10,
            requiredDocuments: ['id', 'proof_of_address'],
            availableCountries: ['DE'],
        );
    }

    public function authenticate(): void
    {
        // Sandbox: no credentials exist. Nothing to authenticate.
    }

    // ── AccountProviderInterface ─────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $accountDetails
     */
    public function openAccount(string $userId, array $accountDetails, string $idempotencyKey): BankAccount
    {
        $accountType = (string) ($accountDetails['account_type'] ?? '');
        $currency = strtoupper((string) ($accountDetails['currency'] ?? 'EUR'));
        $holderName = (string) ($accountDetails['holder_name'] ?? '');

        if ($accountType === '' || $holderName === '') {
            throw new BankOperationException('Sandbox bank requires account_type and holder_name.');
        }

        // Idempotency: the same key returns the same account, never a second.
        $cachedId = Cache::get(self::IDEMPOTENCY_PREFIX.$idempotencyKey);
        if (is_string($cachedId) && $cachedId !== '') {
            return $this->getAccount($cachedId);
        }

        $outcome = (string) ($accountDetails['sandbox_outcome'] ?? 'approved');
        if ($outcome === 'rejected') {
            throw new BankOperationException(
                'Sandbox bank rejected the application: '.((string) ($accountDetails['sandbox_rejection_reason'] ?? 'simulated rejection (sandbox_outcome=rejected)')),
            );
        }
        if (! in_array($outcome, ['approved', 'pending_then_approved'], true)) {
            throw new BankOperationException("Unknown sandbox_outcome: {$outcome}.");
        }

        $accountNumber = $this->testAccountNumber($idempotencyKey);
        $iban = $this->testIban($accountNumber);
        $externalId = 'sbx_'.substr(hash('sha256', $idempotencyKey), 0, 24);
        $status = $outcome === 'pending_then_approved' ? 'pending' : 'active';

        $record = [
            'id'              => $externalId,
            'user_id'         => $userId,
            'account_number'  => $accountNumber,
            'iban'            => $iban,
            'swift'           => 'SANDBX22',
            'currency'        => $currency,
            'account_type'    => $accountType,
            'status'          => $status,
            'holder_name'     => $holderName,
            'holder_address'  => (string) ($accountDetails['holder_address'] ?? ''),
            'outcome'         => $outcome,
            'status_calls'    => 0,
            'idempotency_key' => $idempotencyKey,
        ];
        Cache::put(self::CACHE_PREFIX.$externalId, $record, now()->addDay());
        Cache::put(self::IDEMPOTENCY_PREFIX.$idempotencyKey, $externalId, now()->addDay());

        return $this->toBankAccount($record);
    }

    public function getAccount(string $externalAccountId): BankAccount
    {
        return $this->toBankAccount($this->recordOrFail($externalAccountId));
    }

    public function closeAccount(string $externalAccountId, string $reason, string $idempotencyKey): bool
    {
        $record = $this->recordOrFail($externalAccountId);
        $record['status'] = 'closed';
        $record['closure_reason'] = $reason;
        Cache::put(self::CACHE_PREFIX.$externalAccountId, $record, now()->addDay());

        return true;
    }

    public function freezeAccount(string $externalAccountId, string $reason): bool
    {
        $record = $this->recordOrFail($externalAccountId);
        $record['status'] = 'frozen';
        Cache::put(self::CACHE_PREFIX.$externalAccountId, $record, now()->addDay());

        return true;
    }

    public function unfreezeAccount(string $externalAccountId): bool
    {
        $record = $this->recordOrFail($externalAccountId);
        $record['status'] = 'active';
        Cache::put(self::CACHE_PREFIX.$externalAccountId, $record, now()->addDay());

        return true;
    }

    public function getBalance(string $accountId, ?string $currency = null): BankBalance
    {
        $record = $this->recordOrFail($accountId);

        return new BankBalance(
            accountId: $accountId,
            currency: $currency ?? $record['currency'],
            available: '0.00',
            current: '0.00',
            pending: '0.00',
            reserved: '0.00',
            asOf: Carbon::now(),
            metadata: ['sandbox' => true],
        );
    }

    /**
     * @return array{status: string, provider_reference: ?string}
     */
    public function getAccountStatus(string $externalAccountId): array
    {
        $record = $this->recordOrFail($externalAccountId);

        if (($record['outcome'] ?? '') === 'pending_then_approved' && ($record['status'] ?? '') === 'pending') {
            $record['status_calls'] = (int) ($record['status_calls'] ?? 0) + 1;
            if ($record['status_calls'] >= 2) {
                $record['status'] = 'active';
            }
            Cache::put(self::CACHE_PREFIX.$externalAccountId, $record, now()->addDay());
        }

        $status = match ($record['status'] ?? 'unknown') {
            'active'  => 'approved',
            'pending' => 'pending',
            'closed'  => 'closed',
            'frozen'  => 'approved',
            default   => 'unknown',
        };

        return ['status' => $status, 'provider_reference' => $externalAccountId];
    }

    // ── IBankConnector (delegates to the provider methods above) ─────────

    /**
     * @param  array<string, mixed>  $accountDetails
     */
    public function createAccount(array $accountDetails): BankAccount
    {
        return $this->openAccount(
            (string) ($accountDetails['user_id'] ?? 'sandbox-user'),
            $accountDetails,
            (string) ($accountDetails['idempotency_key'] ?? (string) Str::uuid()),
        );
    }

    /**
     * @param  array<string, mixed>  $transferDetails
     */
    public function initiateTransfer(array $transferDetails): BankTransfer
    {
        throw new BankOperationException(
            'Sandbox bank does not simulate transfers. Transfers are a payment-rails concern, out of scope for the account-opening sandbox.',
        );
    }

    public function getTransferStatus(string $transferId): BankTransfer
    {
        throw new BankOperationException("Sandbox bank has no transfer {$transferId}.");
    }

    public function cancelTransfer(string $transferId): bool
    {
        return false;
    }

    /**
     * @return Collection<int, BankTransaction>
     */
    public function getTransactions(string $accountId, DateTime $from, DateTime $to, int $limit = 100): Collection
    {
        $this->recordOrFail($accountId);

        return collect([]);
    }

    public function getStatement(string $accountId, DateTime $from, DateTime $to, string $format = 'JSON'): BankStatement
    {
        $record = $this->recordOrFail($accountId);

        return new BankStatement(
            id: (string) Str::uuid(),
            bankCode: self::BANK_CODE,
            accountId: $accountId,
            periodFrom: Carbon::instance($from),
            periodTo: Carbon::instance($to),
            format: $format,
            openingBalance: '0.00',
            closingBalance: '0.00',
            currency: $record['currency'],
            transactions: collect([]),
            summary: ['total_debits' => 0, 'total_credits' => 0, 'total_transactions' => 0],
            fileUrl: null,
            fileContent: null,
            generatedAt: Carbon::now(),
            metadata: ['sandbox' => true],
        );
    }

    public function validateIBAN(string $iban): bool
    {
        $iban = strtoupper(str_replace(' ', '', $iban));
        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }

        return $this->mod97($iban) === 1;
    }

    /**
     * @return array<string>
     */
    public function getSupportedCurrencies(): array
    {
        return ['EUR', 'USD', 'GBP'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransferLimits(string $accountId, string $transferType): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public function verifyWebhookSignature(string $payload, string $signature, array $headers): bool
    {
        $secret = $this->webhookSecret !== '' ? $this->webhookSecret : (string) config('banking.providers.sandbox.webhook_secret', '');
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), strtolower($signature));
    }

    /**
     * @return array<string, mixed>
     */
    public function processWebhook(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            throw new BankOperationException('Sandbox webhook payload is not valid JSON.');
        }

        return [
            'event_type' => (string) ($decoded['event_type'] ?? $decoded['type'] ?? 'unknown'),
            'account_id' => (string) ($decoded['account_id'] ?? $decoded['external_id'] ?? ''),
            'status'     => (string) ($decoded['status'] ?? 'unknown'),
            'sandbox'    => true,
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $record
     */
    private function toBankAccount(array $record): BankAccount
    {
        return new BankAccount(
            id: $record['id'],
            bankCode: self::BANK_CODE,
            accountNumber: $record['account_number'],
            iban: $record['iban'],
            swift: $record['swift'],
            currency: $record['currency'],
            accountType: $record['account_type'],
            status: $record['status'],
            holderName: $record['holder_name'],
            holderAddress: $record['holder_address'],
            metadata: ['sandbox' => true, 'sandbox_outcome' => $record['outcome'] ?? 'approved'],
            createdAt: Carbon::now(),
            updatedAt: Carbon::now(),
            closedAt: ($record['status'] ?? '') === 'closed' ? Carbon::now() : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function recordOrFail(string $externalAccountId): array
    {
        $record = Cache::get(self::CACHE_PREFIX.$externalAccountId);
        if (! is_array($record)) {
            throw new AccountNotFoundException("Sandbox bank account not found: {$externalAccountId}");
        }

        return $record;
    }

    /**
     * Deterministic test account number derived from the idempotency key —
     * stable for a given application, different across applications.
     */
    private function testAccountNumber(string $idempotencyKey): string
    {
        $hash = hash('sha256', $idempotencyKey);
        $digits = '';
        for ($i = 0; $i < strlen($hash) && strlen($digits) < 10; $i++) {
            $digits .= ctype_digit($hash[$i]) ? $hash[$i] : (string) (ord($hash[$i]) % 10);
        }

        return 'SBX'.str_pad($digits, 10, '0');
    }

    /**
     * Build a structurally valid DE test IBAN (mod-97 check digits) from the
     * test account number and the fictional sandbox bank code. Valid format
     * only — no real bank, no real account.
     */
    private function testIban(string $accountNumber): string
    {
        $bban = self::SANDBOX_BANK_CODE.$accountNumber;
        // Check digits: 98 - mod97 of the full IBAN shape with '00' checks
        // (mod97() rearranges country+check to the end, as ISO 13616 does).
        $check = 98 - $this->mod97('DE00'.$bban);

        return 'DE'.str_pad((string) $check, 2, '0', STR_PAD_LEFT).$bban;
    }

    /**
     * ISO 13616 mod-97 over an IBAN (letters A=10 … Z=35).
     */
    private function mod97(string $iban): int
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_digit($char) ? $char : (string) (ord($char) - 55);
        }

        $remainder = 0;
        foreach (str_split($numeric) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder;
    }
}
