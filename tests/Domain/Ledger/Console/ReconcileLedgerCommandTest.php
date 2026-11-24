<?php

declare(strict_types=1);

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\Drivers\EloquentDriver;
use App\Domain\Ledger\Services\LedgerService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    LedgerAccount::create(['code' => '1001', 'name' => 'Sender asset', 'type' => 'asset', 'currency' => 'USD']);
    LedgerAccount::create(['code' => '2001', 'name' => 'Funding liability', 'type' => 'liability', 'currency' => 'USD']);

    $this->ledger = new LedgerService(new EloquentDriver());
    $this->ledger->post('Funding', [
        ['account_code' => '1001', 'debit' => '25.0000', 'credit' => '0.0000'],
        ['account_code' => '2001', 'debit' => '0.0000', 'credit' => '25.0000'],
    ], 'Payment', 'pay-1');
});

function statementFile(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'stmt_') . '.json';
    file_put_contents($path, json_encode($rows, JSON_THROW_ON_ERROR));

    return $path;
}

it('exits zero when the provider statement matches the ledger', function (): void {
    $exit = Artisan::call('ledger:reconcile', [
        '--domain'             => 'payment',
        '--account'            => '2001',
        '--provider-statement' => statementFile([
            ['reference' => 'pay-1', 'amount' => '25.00', 'currency' => 'USD'],
        ]),
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('No mismatches');
});

it('exits non-zero and lists an amount mismatch without correcting anything', function (): void {
    $exit = Artisan::call('ledger:reconcile', [
        '--domain'             => 'payment',
        '--account'            => '2001',
        '--provider-statement' => statementFile([
            ['reference' => 'pay-1', 'amount' => '20.00', 'currency' => 'USD'],
            ['reference' => 'pay-9', 'amount' => '1.00', 'currency' => 'USD'],
        ]),
    ]);

    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Amount mismatch pay-1')
        ->and($output)->toContain('Provider-only')
        ->and($output)->toContain('Nothing was auto-corrected');
});

it('fails closed without an account or a statement file', function (): void {
    expect(Artisan::call('ledger:reconcile', ['--domain' => 'payment']))
        ->toBe(1);

    expect(Artisan::call('ledger:reconcile', [
        '--domain'  => 'payment',
        '--account' => '2001',
    ]))->toBe(1);
});
