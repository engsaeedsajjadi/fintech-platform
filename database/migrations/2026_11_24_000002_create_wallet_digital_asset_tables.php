<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('digital_asset_accounts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('account_uuid');
            $table->string('asset_code', 20);
            $table->string('network', 40);
            // Deposit address assigned by the chain/custody provider; null
            // until assigned. Never a private key.
            $table->string('address')->nullable();
            $table->string('custody_type', 20);
            // HSM/KMS key reference only (e.g. KMS key ARN). Never key material.
            $table->string('hsm_key_reference')->nullable();
            $table->string('status', 32);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['account_uuid', 'asset_code', 'network']);
            $table->index('address');
        });

        Schema::create('digital_asset_deposits', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('account_uuid');
            $table->string('asset_code', 20);
            $table->string('network', 40);
            $table->string('tx_hash');
            $table->string('from_address')->nullable();
            $table->string('to_address')->nullable();
            // Minor units (int) — exact, no float.
            $table->bigInteger('amount_minor');
            $table->unsignedInteger('confirmations')->default(0);
            $table->unsignedInteger('required_confirmations');
            $table->string('status', 32);
            $table->string('credit_aggregate_uuid')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->unique(['network', 'tx_hash']);
            $table->index(['account_uuid', 'asset_code']);
        });

        Schema::create('digital_asset_withdrawals', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('account_uuid');
            $table->string('asset_code', 20);
            $table->string('network', 40);
            $table->string('to_address');
            $table->bigInteger('amount_minor');
            $table->bigInteger('fee_minor')->default(0);
            $table->string('status', 32);
            $table->string('idempotency_key')->unique();
            $table->string('tx_hash')->nullable();
            $table->unsignedInteger('confirmations')->default(0);
            $table->unsignedInteger('required_confirmations');
            $table->string('debit_aggregate_uuid')->nullable();
            $table->string('requested_by')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('broadcast_at')->nullable();
            $table->timestamps();

            $table->index(['account_uuid', 'asset_code']);
            $table->index('status');
            $table->index('tx_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_asset_withdrawals');
        Schema::dropIfExists('digital_asset_deposits');
        Schema::dropIfExists('digital_asset_accounts');
    }
};
