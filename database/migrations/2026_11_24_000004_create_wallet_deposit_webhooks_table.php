<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('wallet_deposit_webhooks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('provider', 60);
            $table->string('provider_event_id');
            $table->string('account_uuid');
            $table->string('currency', 10);
            $table->decimal('amount', 20, 4);
            $table->string('provider_reference')->nullable();
            $table->string('status', 32);
            $table->string('fiat_deposit_uuid')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
            $table->index('account_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_deposit_webhooks');
    }
};
