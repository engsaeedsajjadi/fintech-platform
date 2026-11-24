<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('card_issuance_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id');
            $table->string('issuer', 50);
            $table->string('idempotency_key');
            $table->string('status', 30)->default('pending');
            $table->string('card_token')->nullable();
            $table->string('provider_reference')->nullable();
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'issuer', 'idempotency_key']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_issuance_attempts');
    }
};
