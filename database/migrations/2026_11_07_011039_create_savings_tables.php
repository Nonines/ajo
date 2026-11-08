<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('current_version')->default(0);
        });
        Schema::create('savings_product_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('savings_product_id')->constrained();
            $table->unsignedInteger('version');
            $table->text('contract');
            $table->timestamp('published_at');
            $table->unique(['savings_product_id', 'version']);
        });
        Schema::create('savings_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('savings_product_version_id')->constrained();
            $table->uuid('request_key')->unique();
            $table->text('contract');
            $table->string('state')->default('draft')->index();
            $table->timestamp('created_at');
            $table->timestamp('funding_deadline');
            $table->timestamp('funded_at')->nullable();
            $table->timestamp('accrual_starts_at')->nullable();
            $table->timestamp('matures_at')->nullable();
            $table->foreignId('funding_journal_id')->nullable()->constrained('journals');
            $table->timestamp('closed_at')->nullable();
            $table->string('closure_reason')->nullable();
            $table->timestamp('next_process_at')->nullable()->index();
            $table->index(['user_id', 'id']);
        });
        // One immutable row per contractual date makes chronological accrual replay verifiable.
        Schema::create('plan_accruals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('savings_plan_id')->constrained();
            $table->date('accrual_date');
            $table->text('inputs');
            $table->string('fingerprint', 64);
            $table->text('micro_kobo');
            $table->text('cumulative_micro_kobo');
            $table->bigInteger('recognized_kobo');
            $table->foreignId('journal_id')->nullable()->constrained('journals');
            $table->timestamp('posted_at');
            $table->unique(['savings_plan_id', 'accrual_date']);
        });
        Schema::create('plan_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('savings_plan_id')->unique()->constrained();
            $table->foreignId('journal_id')->unique()->constrained('journals');
            $table->bigInteger('principal');
            $table->bigInteger('gross_interest');
            $table->bigInteger('net_interest');
            $table->bigInteger('remaining_debt');
            $table->bigInteger('withholding');
            $table->bigInteger('debt_repayment');
            $table->bigInteger('wallet_credit');
            $table->text('rounding_micro_kobo');
            $table->timestamp('posted_at');
        });
        Schema::create('savings_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('savings_plan_id')->constrained();
            $table->string('operation')->unique();
            $table->text('message');
            $table->timestamp('created_at');
        });
        Schema::create('savings_notices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('savings_plan_id')->unique()->constrained();
            $table->foreignId('user_id')->constrained();
            $table->text('recipient');
            $table->text('data');
            $table->timestamp('created_at');
            $table->timestamp('next_attempt_at')->index();
            $table->timestamp('mail_sent_at')->nullable();
        });
        foreach (['savings_product_versions', 'plan_accruals', 'plan_settlements'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $action) {
                DB::unprepared("CREATE TRIGGER {$table}_no_{$action} BEFORE {$action} ON {$table} BEGIN SELECT RAISE(ABORT, 'Savings evidence is immutable'); END");
            }
            $identity = match ($table) {
                'savings_product_versions' => 'savings_product_id = NEW.savings_product_id AND version = NEW.version',
                'plan_accruals' => 'savings_plan_id = NEW.savings_plan_id AND accrual_date = NEW.accrual_date',
                'plan_settlements' => 'savings_plan_id = NEW.savings_plan_id OR journal_id = NEW.journal_id',
            };
            DB::unprepared("CREATE TRIGGER {$table}_no_replace BEFORE INSERT ON {$table} WHEN EXISTS (SELECT 1 FROM {$table} WHERE id = NEW.id OR ({$identity})) BEGIN SELECT RAISE(ABORT, 'Savings evidence is immutable'); END");
        }
        DB::unprepared("CREATE TRIGGER savings_contract_immutable BEFORE UPDATE ON savings_plans WHEN NEW.contract != OLD.contract OR NEW.user_id != OLD.user_id OR NEW.savings_product_version_id != OLD.savings_product_version_id OR NEW.request_key != OLD.request_key OR NEW.created_at != OLD.created_at OR NEW.funding_deadline != OLD.funding_deadline OR (OLD.funded_at IS NOT NULL AND (NEW.funded_at IS NOT OLD.funded_at OR NEW.accrual_starts_at IS NOT OLD.accrual_starts_at OR NEW.matures_at IS NOT OLD.matures_at OR NEW.funding_journal_id IS NOT OLD.funding_journal_id)) BEGIN SELECT RAISE(ABORT, 'Savings contract is immutable'); END");
    }

    public function down(): void
    {
        foreach (['savings_notices', 'savings_exceptions', 'plan_settlements', 'plan_accruals', 'savings_plans', 'savings_product_versions', 'savings_products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
