<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('reference')->unique();
            $table->text('payer_email');
            $table->string('state')->default('initiated');
            $table->string('provider_id')->nullable()->unique();
            $table->text('access_code')->nullable();
            $table->bigInteger('requested_net');
            foreach (['gross_amount', 'fee_amount', 'wallet_credit', 'debt_repayment'] as $column) {
                $table->bigInteger($column)->default(0);
            }
            $table->foreignId('recognition_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->foreignId('fee_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->string('provider_status')->nullable();
            $table->timestamp('recognized_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('next_verify_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
        });
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->text('payload');
            $table->timestamp('received_at');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('next_attempt_at')->index();
            $table->unsignedInteger('attempts')->default(0);
        });
        Schema::create('payment_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deposit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('webhook_event_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('key')->unique();
            $table->string('code');
            $table->string('message');
            $table->string('resource_type')->nullable();
            $table->string('resource_id')->nullable();
            $table->boolean('provider_open')->default(false);
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('next_refund_page')->default(1);
            $table->unsignedInteger('refund_page_offset')->default(0);
            $table->timestamp('refund_scan_to')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('payment_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deposit_id')->constrained()->restrictOnDelete();
            $table->string('provider_refund_id')->unique();
            $table->string('provider_dispute_id')->nullable();
            $table->string('fingerprint', 64);
            $table->bigInteger('amount');
            foreach (['principal', 'fee', 'wallet_collected', 'receivable_created'] as $column) {
                $table->bigInteger($column)->default(0);
            }
            $table->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->text('evidence');
            $table->timestamp('verified_at');
        });
        Schema::create('payment_notices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('deposit_id')->constrained()->restrictOnDelete();
            $table->string('operation_key')->unique();
            $table->text('recipient');
            $table->text('data');
            $table->timestamp('created_at');
            $table->timestamp('mail_sent_at')->nullable();
            $table->timestamp('next_attempt_at')->index();
        });
        Schema::create('payment_scans', function (Blueprint $table): void {
            $table->id();
            $table->string('resource')->unique();
            $table->unsignedInteger('next_page')->default(1);
            $table->timestamp('window_from')->nullable();
            $table->timestamp('window_to')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('observed_through')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['payment_scans', 'payment_notices', 'payment_reversals', 'payment_exceptions', 'webhook_events', 'deposits'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
