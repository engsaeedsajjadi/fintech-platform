<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('conversion_quotes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('account_uuid');
            $table->string('from_asset', 20);
            $table->string('to_asset', 20);
            // Amounts in MAJOR units as exact decimals (scale 18 covers
            // crypto precision; fiat uses the same representation).
            $table->decimal('amount', 36, 18);
            // Exact decimal string rate from the rate provider, e.g. '0.8500000000'.
            $table->string('rate', 40);
            $table->decimal('fee', 36, 18)->default(0);
            $table->decimal('converted_amount', 36, 18);
            $table->string('provider');
            $table->string('status', 32);
            $table->timestamp('expires_at');
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['account_uuid', 'status']);
            $table->index('expires_at');
        });

        Schema::create('conversions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('account_uuid');
            $table->string('quote_uuid')->nullable();
            $table->string('from_asset', 20);
            $table->string('to_asset', 20);
            $table->decimal('from_amount', 36, 18);
            $table->decimal('fee', 36, 18)->default(0);
            $table->decimal('to_amount', 36, 18);
            $table->string('rate', 40);
            $table->string('provider');
            $table->string('status', 32);
            $table->string('idempotency_key')->unique();
            $table->json('journal_entry_ids')->nullable();
            $table->string('asset_transfer_uuid')->nullable();
            $table->timestamps();

            $table->index(['account_uuid', 'status']);
            $table->index('quote_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversions');
        Schema::dropIfExists('conversion_quotes');
    }
};
