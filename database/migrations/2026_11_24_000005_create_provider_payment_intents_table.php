<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Provider-independent payment intents (Phase 2 unified payment
        // engine). One row per intent, whatever the provider (Stripe,
        // HyperSwitch, Paysera, bank rail, Iranian sandbox rail). Money
        // is integer minor units — never a float. State uses the canonical
        // TransactionState vocabulary; `idempotency_key` is unique so a
        // retried create returns the same intent instead of moving money
        // twice. Reversals and refunds are separate intents linked via
        // `reverses_intent_id`; a terminal intent is never edited in place.
        //
        // Table is `provider_payment_intents`: `payment_intents` already
        // exists for the MobilePayment merchant flow (2026-02-07).
        Schema::create('provider_payment_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_uuid')->nullable()->index();
            $table->string('direction', 16)->index(); // internal|external|incoming|outgoing
            $table->string('kind', 16)->index(); // deposit|withdrawal|transfer|refund|reversal
            $table->string('provider', 64)->index();
            $table->string('provider_reference')->nullable()->index();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('state', 32)->index(); // TransactionState value
            $table->string('idempotency_key')->unique();
            $table->uuid('reverses_intent_id')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->dateTime('unknown_since_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_payment_intents');
    }
};
