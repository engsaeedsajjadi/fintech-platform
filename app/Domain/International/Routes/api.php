<?php

declare(strict_types=1);

use App\Domain\International\Http\Controllers\CounterpartyController;
use App\Domain\International\Http\Controllers\InternationalAccountController;
use App\Domain\International\Http\Controllers\InternationalTransferController;
use App\Domain\International\Http\Controllers\QuotationController;
use App\Domain\International\Http\Controllers\ReconciliationController;
use App\Support\StaffRoles;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\RoleMiddleware;

/*
|--------------------------------------------------------------------------
| International (P2P) API — provider-independent by design
|--------------------------------------------------------------------------
|
| No route here executes a real rail: every rail is disabled and the
| only gateway refuses dispatch. Operator routes (screening decisions,
| external dispatch, settlement recording, reconciliation) require a
| compliance role. Loaded automatically by ModuleRouteLoader from
| app/Domain/International/Routes/api.php (see routes/api.php).
|
| Phase 8: the group runs under the `transaction` rate-limit bucket
| (30/min per user) — previously these authenticated POST endpoints had
| no rate limit at all. (Skipped in tests unless
| rate_limiting.force_in_tests is set.)
*/

Route::prefix('v1/international')->name('api.international.')->middleware(['auth:sanctum', 'api.rate_limit:transaction'])->group(function (): void {
    // Multi-currency accounts
    Route::get('/accounts', [InternationalAccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts', [InternationalAccountController::class, 'store'])->name('accounts.store');
    Route::get('/accounts/{id}', [InternationalAccountController::class, 'show'])->name('accounts.show');

    // Counterparties + beneficiaries
    Route::get('/counterparties', [CounterpartyController::class, 'indexCounterparties'])->name('counterparties.index');
    Route::post('/counterparties', [CounterpartyController::class, 'storeCounterparty'])->name('counterparties.store');
    Route::get('/counterparties/{id}', [CounterpartyController::class, 'showCounterparty'])->name('counterparties.show');
    Route::get('/counterparties/{id}/beneficiaries', [CounterpartyController::class, 'indexBeneficiaries'])->name('beneficiaries.index');
    Route::post('/counterparties/{id}/beneficiaries', [CounterpartyController::class, 'storeBeneficiary'])->name('beneficiaries.store');

    // Exchange quotations
    Route::post('/quotations', [QuotationController::class, 'store'])->name('quotations.store');
    Route::post('/quotations/{id}/accept', [QuotationController::class, 'accept'])->name('quotations.accept');

    // Transfers (owner)
    Route::get('/transfers', [InternationalTransferController::class, 'index'])->name('transfers.index');
    Route::post('/transfers', [InternationalTransferController::class, 'store'])->name('transfers.store');
    Route::get('/transfers/{id}', [InternationalTransferController::class, 'show'])->name('transfers.show');
    Route::get('/transfers/{id}/history', [InternationalTransferController::class, 'history'])->name('transfers.history');
    Route::post('/transfers/{id}/submit', [InternationalTransferController::class, 'submit'])->name('transfers.submit');
    Route::post('/transfers/{id}/process', [InternationalTransferController::class, 'process'])->name('transfers.process');
    Route::post('/transfers/{id}/reverse', [InternationalTransferController::class, 'reverse'])->name('transfers.reverse');

    // Operator-only (compliance): screening, external dispatch, settlement, reconciliation
    Route::middleware(RoleMiddleware::using(StaffRoles::COMPLIANCE))->group(function (): void {
        Route::post('/transfers/{id}/screening', [InternationalTransferController::class, 'beginScreening'])->name('transfers.screening');
        Route::post('/transfers/{id}/screening-decision', [InternationalTransferController::class, 'recordScreeningDecision'])->name('transfers.screening-decision');
        Route::post('/transfers/{id}/dispatch', [InternationalTransferController::class, 'dispatch'])->name('transfers.dispatch');
        Route::post('/transfers/{id}/settle', [InternationalTransferController::class, 'settle'])->name('transfers.settle');

        Route::post('/reconciliation/clearing', [ReconciliationController::class, 'clearing'])->name('reconciliation.clearing');
        Route::post('/reconciliation/statement', [ReconciliationController::class, 'statement'])->name('reconciliation.statement');
        Route::get('/reconciliation/{id}', [ReconciliationController::class, 'show'])->name('reconciliation.show');
    });
});
