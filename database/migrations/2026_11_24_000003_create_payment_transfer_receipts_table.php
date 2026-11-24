<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Receipts issued by PaymentReceiptService for completed transfers.
        // Amounts are stored twice: exact integer minor units (the source
        // of truth) and the decimal major-unit string (for display).
        Schema::create('payment_transfer_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('receipt_number')->unique();
            $table->unsignedBigInteger('payment_transaction_id')->nullable();
            $table->string('reference')->index();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('sender_account_code');
            $table->string('recipient_account_code');
            $table->bigInteger('amount_minor');
            $table->string('amount_decimal');
            $table->bigInteger('fee_minor')->default(0);
            $table->string('fee_decimal')->default('0');
            $table->string('currency', 3);
            $table->string('state');
            $table->string('journal_entry_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['reference', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transfer_receipts');
    }
};
