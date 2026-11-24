<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('otc_quotes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('quote_id')->unique();
            $table->string('account_id');
            $table->string('side', 10); // buy|sell
            $table->string('base_currency', 10);
            $table->string('quote_currency', 10);
            $table->decimal('amount', 36, 18);
            $table->decimal('price', 36, 18);
            $table->decimal('fee', 36, 18)->default(0);
            // Total quote-currency consideration for a buy (amount*price + fee),
            // or quote-currency proceeds before fee for a sell.
            $table->decimal('total', 36, 18);
            $table->string('status', 20)->default('quoted'); // quoted|accepted|expired|settled|cancelled
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otc_quotes');
    }
};
