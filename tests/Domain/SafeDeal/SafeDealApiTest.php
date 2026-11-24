<?php

declare(strict_types=1);

namespace Tests\Domain\SafeDeal;

use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SafeDealApiTest extends TestCase
{
    public function test_create_trade_and_full_api_flow(): void
    {
        $buyer = $this->user;
        $seller = $this->business_user;

        // Phase 8: counterparty verification requires approved platform
        // KYC (enhanced level for the 'enhanced' verifications below).
        $buyer->update(['kyc_status' => 'approved', 'kyc_level' => 'enhanced']);
        $seller->update(['kyc_status' => 'approved', 'kyc_level' => 'enhanced']);

        Sanctum::actingAs($buyer, ['read', 'write', 'delete']);

        $create = $this->postJson('/api/v1/safedeal/trades', [
            'seller_id' => (string) $seller->id,
            'amount'    => '100.0000',
            'currency'  => 'USD',
            'terms'     => [
                'description'       => 'API trade',
                'conditions'        => ['required_documents' => ['agreement_copy']],
                'delivery_deadline' => now()->addDays(15)->toDateString(),
            ],
            'milestones' => [['title' => 'Delivery', 'amount' => '100.0000']],
        ]);

        $create->assertCreated();
        $tradeId = $create->json('data.trade_id');
        $this->assertNotEmpty($tradeId);
        $this->assertSame('draft', $create->json('data.status'));

        $this->postJson("/api/v1/safedeal/trades/{$tradeId}/verify", ['kyc_reference' => 'kyc-buyer-api', 'level' => 'enhanced'])->assertOk();

        Sanctum::actingAs($seller, ['read', 'write', 'delete']);
        $this->postJson("/api/v1/safedeal/trades/{$tradeId}/verify", ['kyc_reference' => 'kyc-seller-api', 'level' => 'enhanced'])->assertOk();

        Sanctum::actingAs($buyer, ['read', 'write', 'delete']);
        $this->postJson("/api/v1/safedeal/trades/{$tradeId}/agreement/submit")->assertOk();
        Sanctum::actingAs($seller, ['read', 'write', 'delete']);
        $this->postJson("/api/v1/safedeal/trades/{$tradeId}/agreement/accept")->assertOk();

        Sanctum::actingAs($buyer, ['read', 'write', 'delete']);
        $doc = $this->postJson("/api/v1/safedeal/trades/{$tradeId}/documents", ['type' => 'agreement_copy', 'reference' => 'doc-api-1']);
        $doc->assertCreated();
        $documentId = $doc->json('data.id');

        Sanctum::actingAs($seller, ['read', 'write', 'delete']);
        $this->postJson("/api/v1/safedeal/trades/{$tradeId}/documents/{$documentId}/review", ['approve' => true])->assertOk();

        $risk = $this->postJson("/api/v1/safedeal/trades/{$tradeId}/risk-assessment");
        $risk->assertOk();
        $this->assertSame('risk_assessed', $risk->json('data.status'));

        Sanctum::actingAs($buyer, ['read', 'write', 'delete']);
        $this->postJson("/api/v1/safedeal/trades/{$tradeId}/funding/authorize")->assertOk();
        $funded = $this->postJson("/api/v1/safedeal/trades/{$tradeId}/funding/confirm");
        $funded->assertOk();
        $this->assertSame('funded', $funded->json('data.status'));

        // Invalid transition over the API: settle from FUNDED is rejected.
        $this->postJson("/api/v1/safedeal/trades/{$tradeId}/settle")->assertStatus(500);

        // A stranger cannot see the trade.
        $stranger = \App\Models\User::factory()->create();
        Sanctum::actingAs($stranger, ['read', 'write', 'delete']);
        $this->getJson("/api/v1/safedeal/trades/{$tradeId}")->assertForbidden();
    }

    public function test_create_trade_validation_rejects_bad_milestones(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $this->postJson('/api/v1/safedeal/trades', [
            'seller_id'  => (string) $this->business_user->id,
            'amount'     => '100.0000',
            'currency'   => 'USD',
            'terms'      => ['description' => 'Bad'],
            'milestones' => [],
        ])->assertStatus(422);
    }
}
