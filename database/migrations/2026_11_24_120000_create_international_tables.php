<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * International (Phase Five) tables.
 *
 * Provider-independent by design: these tables store ONLY our own
 * workflow state (references, amounts, statuses, audit). They store NO
 * bank account numbers, NO full IBANs, NO provider endpoints and NO
 * credentials — none exists and none is invented.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('international_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->string('ledger_account_code')->unique();
            $table->string('nickname')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['user_id', 'currency']);
        });

        Schema::create('counterparties', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->string('full_name');
            $table->string('country', 2);
            $table->string('screening_status')->default('not_screened');
            $table->timestamp('last_screened_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'screening_status']);
        });

        Schema::create('beneficiaries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('counterparty_id')->constrained('counterparties')->cascadeOnDelete();
            $table->string('display_name');
            $table->string('country', 2);
            $table->string('bank_name')->nullable();
            $table->string('bic', 11)->nullable();
            $table->string('iban_last4', 4)->nullable();
            $table->string('account_last4', 4)->nullable();
            $table->string('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['counterparty_id', 'is_active']);
        });

        Schema::create('exchange_quotations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source_currency', 3);
            $table->string('destination_currency', 3);
            $table->bigInteger('amount_minor');
            $table->decimal('rate', 20, 10);
            $table->bigInteger('destination_amount_minor');
            $table->string('source')->default('manual');
            $table->string('status')->default('quoted');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('international_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('international_account_id')->constrained('international_accounts')->cascadeOnDelete();
            $table->foreignUuid('counterparty_id')->constrained('counterparties')->cascadeOnDelete();
            $table->foreignUuid('beneficiary_id')->constrained('beneficiaries')->cascadeOnDelete();
            $table->foreignUuid('exchange_quotation_id')->nullable()->constrained('exchange_quotations')->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('end_to_end_reference')->unique();
            $table->string('external_reference')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('payload_hash');
            $table->bigInteger('amount_minor');
            $table->bigInteger('fee_minor')->default(0);
            $table->string('currency', 3);
            $table->string('destination_currency', 3);
            $table->bigInteger('destination_amount_minor');
            $table->string('purpose');
            $table->string('purpose_detail')->nullable();
            $table->string('rail');
            $table->string('status')->default('draft');
            $table->string('screening_status')->default('not_screened');
            $table->string('screening_notes')->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('journal_entry_id')->nullable();
            $table->string('fee_journal_entry_id')->nullable();
            $table->string('settlement_journal_entry_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['rail', 'status']);
        });

        Schema::create('settlement_instructions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('international_transfer_id')->constrained('international_transfers')->cascadeOnDelete();
            $table->string('rail');
            $table->string('charge_bearer')->default('sha');
            $table->date('value_date')->nullable();
            $table->string('remittance_information')->nullable();
            $table->string('status')->default('pending');
            $table->string('failure_reason')->nullable();
            $table->string('external_reference')->nullable();
            $table->timestamps();

            $table->index(['international_transfer_id', 'status']);
        });

        Schema::create('international_transfer_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('international_transfer_id')->constrained('international_transfers')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index('international_transfer_id');
        });

        Schema::create('international_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('international_transfer_id')->nullable()->constrained('international_transfers')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index('international_transfer_id');
            $table->index('action');
        });

        Schema::create('international_reconciliation_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('domain')->default('international');
            $table->string('gl_account_code');
            $table->string('gl_balance');
            $table->string('domain_balance');
            $table->string('variance');
            $table->string('status');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['domain', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('international_reconciliation_reports');
        Schema::dropIfExists('international_audit_logs');
        Schema::dropIfExists('international_transfer_status_history');
        Schema::dropIfExists('settlement_instructions');
        Schema::dropIfExists('international_transfers');
        Schema::dropIfExists('exchange_quotations');
        Schema::dropIfExists('beneficiaries');
        Schema::dropIfExists('counterparties');
        Schema::dropIfExists('international_accounts');
    }
};
