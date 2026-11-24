<?php

declare(strict_types=1);

use App\Domain\SafeDeal\Http\Controllers\SafeDealTradeController;
use Illuminate\Support\Facades\Route;

// SafeDeal — simulated escrow trade lifecycle. Virtual ledger accounts
// only; no real funds move and no regulated service is advertised.
//
// Phase 8: the whole group runs under the `transaction` rate-limit bucket
// (30/min per user, like the other money-moving endpoints) — previously
// these authenticated POST endpoints had no rate limit at all, so a
// single account could hammer transfer/trade creation, quotations and
// dispute opening without limit. (Skipped in tests unless
// rate_limiting.force_in_tests is set.)
Route::middleware(['auth:sanctum', 'api.rate_limit:transaction'])->prefix('v1/safedeal')->name('api.safedeal.')->group(function (): void {
    Route::post('/trades', [SafeDealTradeController::class, 'store'])->name('trades.store');
    Route::get('/trades/{tradeId}', [SafeDealTradeController::class, 'show'])->name('trades.show');

    Route::post('/trades/{tradeId}/verify', [SafeDealTradeController::class, 'verify'])->name('trades.verify');
    Route::post('/trades/{tradeId}/agreement/submit', [SafeDealTradeController::class, 'submitAgreement'])->name('trades.agreement.submit');
    Route::post('/trades/{tradeId}/agreement/accept', [SafeDealTradeController::class, 'acceptAgreement'])->name('trades.agreement.accept');

    Route::post('/trades/{tradeId}/documents', [SafeDealTradeController::class, 'submitDocument'])->name('trades.documents.submit');
    Route::post('/trades/{tradeId}/documents/{documentId}/review', [SafeDealTradeController::class, 'reviewDocument'])->name('trades.documents.review');

    Route::post('/trades/{tradeId}/risk-assessment', [SafeDealTradeController::class, 'assessRisk'])->name('trades.risk');
    Route::post('/trades/{tradeId}/funding/authorize', [SafeDealTradeController::class, 'authorizeFunding'])->name('trades.funding.authorize');
    Route::post('/trades/{tradeId}/funding/confirm', [SafeDealTradeController::class, 'confirmPayment'])->name('trades.funding.confirm');

    Route::post('/trades/{tradeId}/milestones/start', [SafeDealTradeController::class, 'startMilestones'])->name('trades.milestones.start');
    Route::post('/trades/{tradeId}/milestones/{milestoneId}/complete', [SafeDealTradeController::class, 'completeMilestone'])->name('trades.milestones.complete');

    Route::post('/trades/{tradeId}/delivery/confirm', [SafeDealTradeController::class, 'confirmDelivery'])->name('trades.delivery.confirm');
    Route::post('/trades/{tradeId}/approve', [SafeDealTradeController::class, 'approve'])->name('trades.approve');
    Route::post('/trades/{tradeId}/settle', [SafeDealTradeController::class, 'settle'])->name('trades.settle');

    Route::post('/trades/{tradeId}/disputes', [SafeDealTradeController::class, 'openDispute'])->name('trades.disputes.open');
    Route::post('/trades/{tradeId}/disputes/{disputeId}/resolve', [SafeDealTradeController::class, 'resolveDispute'])->name('trades.disputes.resolve');

    Route::post('/trades/{tradeId}/cancel', [SafeDealTradeController::class, 'cancel'])->name('trades.cancel');
});
