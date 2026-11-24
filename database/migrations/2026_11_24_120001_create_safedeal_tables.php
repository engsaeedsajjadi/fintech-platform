<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('safe_deal_trades', function (Blueprint $table): void {
            $table->id();
            $table->string('trade_id')->unique();
            $table->string('buyer_id');
            $table->string('seller_id');
            $table->decimal('amount', 20, 4);
            $table->string('currency', 10);
            $table->string('status', 40)->default('draft');
            $table->string('buyer_verification', 20)->default('unverified');
            $table->string('seller_verification', 20)->default('unverified');
            $table->string('buyer_verification_level', 20)->nullable();
            $table->string('seller_verification_level', 20)->nullable();
            $table->json('terms')->nullable();
            $table->unsignedInteger('terms_version')->default(1);
            $table->integer('risk_score')->nullable();
            $table->string('risk_level', 20)->nullable();
            $table->json('risk_factors')->nullable();
            $table->timestamp('buyer_approved_at')->nullable();
            $table->timestamp('seller_approved_at')->nullable();
            $table->timestamp('funded_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['buyer_id', 'status']);
            $table->index(['seller_id', 'status']);
            $table->index('status');
        });

        Schema::create('safe_deal_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_id')->constrained('safe_deal_trades')->cascadeOnDelete();
            $table->string('title');
            $table->decimal('amount', 20, 4);
            $table->string('status', 20)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('safe_deal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_id')->constrained('safe_deal_trades')->cascadeOnDelete();
            $table->string('type', 100);
            $table->string('reference', 500);
            $table->string('submitted_by');
            $table->string('status', 20)->default('submitted');
            $table->string('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('safe_deal_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_id')->constrained('safe_deal_trades')->cascadeOnDelete();
            $table->string('dispute_id')->unique();
            $table->string('opened_by');
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->string('status', 20)->default('open');
            $table->string('resolution', 30)->nullable();
            $table->string('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('seller_share')->nullable();
            $table->timestamps();
        });

        Schema::create('safe_deal_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_id')->constrained('safe_deal_trades')->cascadeOnDelete();
            $table->string('event', 100);
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['trade_id', 'id']);
        });

        Schema::create('safe_deal_audit_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_id')->constrained('safe_deal_trades')->cascadeOnDelete();
            $table->string('trade_uuid')->unique();
            $table->string('final_status', 40);
            $table->json('event_history');
            $table->string('event_history_hash', 64);
            $table->json('ledger_entry_ids')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('audited_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_deal_audit_records');
        Schema::dropIfExists('safe_deal_events');
        Schema::dropIfExists('safe_deal_disputes');
        Schema::dropIfExists('safe_deal_documents');
        Schema::dropIfExists('safe_deal_milestones');
        Schema::dropIfExists('safe_deal_trades');
    }
};
