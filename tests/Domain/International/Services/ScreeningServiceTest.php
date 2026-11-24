<?php

declare(strict_types=1);

use App\Domain\Compliance\Contracts\SanctionsScreeningInterface;
use App\Domain\International\Enums\ScreeningOutcome;
use App\Domain\International\Models\Beneficiary;
use App\Domain\International\Models\Counterparty;
use App\Domain\International\Services\AmlScreeningAdapter;
use App\Domain\International\Services\InternationalAccountService;
use App\Domain\International\Services\InternationalAuditService;
use App\Domain\International\Services\InternationalScreeningService;
use App\Domain\International\Services\InternationalTransferService;
use App\Domain\International\Services\ManualReviewScreeningAdapter;
use App\Domain\International\Services\QuotationService;
use App\Domain\International\Services\TransferFeeService;
use App\Domain\Ledger\Services\Drivers\EloquentDriver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Payment\Services\TransferFeeCalculator;

/** @return array{0: Counterparty, 1: Beneficiary} */
function screeningFixtures(): array
{
    $counterparty = Counterparty::create([
        'user_id'          => 1,
        'type'             => 'individual',
        'full_name'        => 'Jane Example',
        'country'          => 'DE',
        'screening_status' => 'not_screened',
    ]);

    $beneficiary = Beneficiary::create([
        'user_id'         => 1,
        'counterparty_id' => $counterparty->id,
        'display_name'    => 'Jane Example',
        'country'         => 'DE',
        'currency'        => 'EUR',
        'is_active'       => true,
    ]);

    return [$counterparty, $beneficiary];
}

it('defaults to manual review and never auto-clears', function (): void {
    [$counterparty, $beneficiary] = screeningFixtures();

    $adapter = new ManualReviewScreeningAdapter();
    $result = $adapter->screen($counterparty, $beneficiary);

    expect($result->outcome)->toBe(ScreeningOutcome::MANUAL_REVIEW_REQUIRED)
        ->and($result->isDecisive())->toBeFalse();
});

it('records a manual-review outcome on the transfer via the screening service', function (): void {
    // Build a real persisted transfer through the service graph, then
    // run the screening service on it with the default (manual) adapter.
    $screeningForTransfer = new InternationalScreeningService(new InternationalAuditService());
    $service = new InternationalTransferService(
        new LedgerService(new EloquentDriver()),
        new TransferFeeService(new TransferFeeCalculator()),
        new QuotationService(new InternationalAuditService()),
        $screeningForTransfer,
        new InternationalAuditService(),
        new InternationalAccountService(new InternationalAuditService()),
    );

    $accounts = new InternationalAccountService(new InternationalAuditService());
    $account = $accounts->openAccount($this->user, 'USD');
    $counterparty = Counterparty::create([
        'user_id'          => $this->user->id,
        'type'             => 'individual',
        'full_name'        => 'Jane Example',
        'country'          => 'DE',
        'screening_status' => 'not_screened',
    ]);
    $beneficiary = Beneficiary::create([
        'user_id'         => $this->user->id,
        'counterparty_id' => $counterparty->id,
        'display_name'    => 'Jane Example',
        'country'         => 'DE',
        'currency'        => 'USD',
        'is_active'       => true,
    ]);

    $transfer = $service->createTransfer($this->user, [
        'international_account_id' => $account->id,
        'counterparty_id'          => $counterparty->id,
        'beneficiary_id'           => $beneficiary->id,
        'amount_minor'             => 10000,
        'currency'                 => 'USD',
        'destination_currency'     => 'USD',
        'purpose'                  => 'family_support',
        'rail'                     => 'swift',
    ], 'key-screening-service');

    $screening = new InternationalScreeningService();
    expect($screening->adapterName())->toBe('manual-review');

    $result = $screening->screen($transfer);

    expect($result->outcome)->toBe(ScreeningOutcome::MANUAL_REVIEW_REQUIRED)
        ->and($counterparty->refresh()->screening_status)->toBe('manual_review_required')
        ->and($transfer->refresh()->screening_status)->toBe('manual_review_required');
});

it('maps a sanctions hit to HIT and a clean result to CLEAR via the AML adapter', function (): void {
    [$counterparty, $beneficiary] = screeningFixtures();

    $hitProvider = new class () implements SanctionsScreeningInterface {
        public function screenIndividual(array $searchParams): array
        {
            return ['matches' => ['OFAC' => [['name' => 'Jane Example']]], 'lists_checked' => ['OFAC'], 'total_matches' => 1];
        }

        public function screenAddress(string $address, string $chain): array
        {
            return ['matches' => [], 'lists_checked' => [], 'total_matches' => 0];
        }

        public function getName(): string
        {
            return 'fake-sanctions';
        }
    };

    $cleanProvider = new class () implements SanctionsScreeningInterface {
        public function screenIndividual(array $searchParams): array
        {
            return ['matches' => [], 'lists_checked' => ['OFAC'], 'total_matches' => 0];
        }

        public function screenAddress(string $address, string $chain): array
        {
            return ['matches' => [], 'lists_checked' => [], 'total_matches' => 0];
        }

        public function getName(): string
        {
            return 'fake-sanctions';
        }
    };

    expect((new AmlScreeningAdapter($hitProvider))->screen($counterparty, $beneficiary)->outcome)
        ->toBe(ScreeningOutcome::HIT);

    expect((new AmlScreeningAdapter($cleanProvider))->screen($counterparty, $beneficiary)->outcome)
        ->toBe(ScreeningOutcome::CLEAR);
});

it('maps a provider error to ERROR so screening fails closed', function (): void {
    [$counterparty, $beneficiary] = screeningFixtures();

    $brokenProvider = new class () implements SanctionsScreeningInterface {
        public function screenIndividual(array $searchParams): array
        {
            throw new RuntimeException('provider down');
        }

        public function screenAddress(string $address, string $chain): array
        {
            return ['matches' => [], 'lists_checked' => [], 'total_matches' => 0];
        }

        public function getName(): string
        {
            return 'broken';
        }
    };

    $result = (new AmlScreeningAdapter($brokenProvider))->screen($counterparty, $beneficiary);

    expect($result->outcome)->toBe(ScreeningOutcome::ERROR)
        ->and($result->isDecisive())->toBeFalse();
});
