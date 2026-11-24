<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('fiat_deposits', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('account_uuid');
            $table->string('currency', 10);
            // Major units, ledger scale 4 (e.g. 100.5000 USD).
            $table->decimal('amount', 20, 4);
            $table->decimal('fee', 20, 4)->default(0);
            $table->decimal('net_amount', 20, 4);
            $table->string('status', 32);
            $table->string('idempotency_key')->unique();
            $table->string('provider_reference')->nullable();
            $table->string('journal_entry_id')->nullable();
            $table->string('requested_by')->nullable();
            $table->timestamps();

            $table->index(['account_uuid', 'currency']);
            $table->index('created_at');
        });

        Schema::create('fiat_withdrawals', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('account_uuid');
            $table->string('currency', 10);
            $table->decimal('amount', 20, 4);
            $table->decimal('fee', 20, 4)->default(0);
            $table->decimal('net_amount', 20, 4);
            $table->string('status', 32);
            $table->string('idempotency_key')->unique();
            $table->string('destination_reference')->nullable();
            $table->string('journal_entry_id')->nullable();
            $table->string('requested_by')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['account_uuid', 'currency']);
            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('fiat_transfers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('from_account_uuid');
            $table->string('to_account_uuid');
            $table->string('currency', 10);
            $table->decimal('amount', 20, 4);
            $table->decimal('fee', 20, 4)->default(0);
            $table->string('status', 32);
            $table->string('idempotency_key')->unique();
            $table->string('journal_entry_id')->nullable();
            $table->string('requested_by')->nullable();
            $table->timestamps();

            $table->index(['from_account_uuid', 'currency']);
            $table->index(['to_account_uuid', 'currency']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiat_transfers');
        Schema::dropIfExists('fiat_withdrawals');
        Schema::dropIfExists('fiat_deposits');
    }
};
