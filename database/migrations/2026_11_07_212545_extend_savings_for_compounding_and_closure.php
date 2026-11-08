<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite table rebuilds discard triggers; retain the existing financial guards.
        /** @var list<object{name: string, sql: literal-string}> $triggers */
        $triggers = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name IN ('savings_plans', 'plan_settlements')");
        Schema::create('savings_calculations', function (Blueprint $table): void {
            $table->string('fingerprint', 64)->primary();
            $table->text('contract');
            $table->unsignedInteger('through_days');
            $table->timestamp('next_process_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure')->nullable();
        });
        Schema::create('savings_calculation_steps', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 64);
            $table->foreign('fingerprint')->references('fingerprint')->on('savings_calculations');
            $table->unsignedInteger('completed_days');
            $table->text('micro_kobo');
            $table->bigInteger('recognized');
            $table->bigInteger('capitalized');
            $table->timestamp('created_at');
            $table->unique(['fingerprint', 'completed_days']);
        });
        foreach (['UPDATE', 'DELETE'] as $action) {
            DB::unprepared("CREATE TRIGGER savings_calculation_steps_no_{$action} BEFORE {$action} ON savings_calculation_steps BEGIN SELECT RAISE(ABORT, 'Calculation evidence is immutable'); END");
        }
        DB::unprepared("CREATE TRIGGER savings_calculation_steps_no_replace BEFORE INSERT ON savings_calculation_steps WHEN EXISTS (SELECT 1 FROM savings_calculation_steps WHERE id = NEW.id OR (fingerprint = NEW.fingerprint AND completed_days = NEW.completed_days)) BEGIN SELECT RAISE(ABORT, 'Calculation evidence is immutable'); END");
        DB::unprepared("CREATE TRIGGER savings_calculations_immutable BEFORE UPDATE ON savings_calculations WHEN NEW.fingerprint IS NOT OLD.fingerprint OR NEW.contract IS NOT OLD.contract OR NEW.through_days IS NOT OLD.through_days OR (OLD.completed_at IS NOT NULL AND NEW.completed_at IS NOT OLD.completed_at) OR (OLD.failure IS NOT NULL AND NEW.failure IS NOT OLD.failure) BEGIN SELECT RAISE(ABORT, 'Calculation inputs are immutable'); END");
        DB::unprepared("CREATE TRIGGER savings_calculations_no_replace BEFORE INSERT ON savings_calculations WHEN EXISTS (SELECT 1 FROM savings_calculations WHERE fingerprint = NEW.fingerprint) BEGIN SELECT RAISE(ABORT, 'Calculation inputs are immutable'); END");
        Schema::table('savings_plans', function (Blueprint $table): void {
            $table->foreignId('parent_plan_id')->nullable()->unique()->constrained('savings_plans');
            $table->text('rollover_election')->nullable();
        });
        DB::unprepared("CREATE TRIGGER savings_parent_immutable BEFORE UPDATE ON savings_plans WHEN NEW.parent_plan_id IS NOT OLD.parent_plan_id BEGIN SELECT RAISE(ABORT, 'Savings parent is immutable'); END");
        Schema::create('plan_capitalizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('savings_plan_id')->constrained();
            $table->date('boundary_date');
            $table->bigInteger('amount');
            $table->bigInteger('cumulative_capitalized');
            $table->foreignId('journal_id')->nullable()->constrained('journals');
            $table->timestamp('posted_at');
            $table->unique(['savings_plan_id', 'boundary_date']);
        });
        Schema::table('plan_settlements', function (Blueprint $table): void {
            $table->bigInteger('remaining_principal')->nullable();
            foreach (['capitalized_interest', 'forfeiture', 'forfeited_capitalized', 'forfeited_payable', 'principal_penalty', 'waived_forfeiture', 'waived_penalty', 'incurred_fees'] as $column) {
                $table->bigInteger($column)->default(0);
            }
            $table->foreignId('forfeiture_journal_id')->nullable()->constrained('journals');
            $table->foreignId('penalty_journal_id')->nullable()->constrained('journals');
            $table->string('closure_reason')->default('matured_withdrawal');
            $table->date('accrual_through')->nullable();
            $table->foreignId('waiver_id')->nullable();
            $table->foreignId('approval_request_id')->nullable()->constrained();
        });
        Schema::create('plan_waivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('savings_plan_id')->constrained();
            $table->foreignId('approval_request_id')->unique()->constrained();
            $table->bigInteger('interest_cap');
            $table->bigInteger('principal_cap');
            $table->timestamp('approved_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->foreignId('settlement_id')->nullable()->unique()->constrained('plan_settlements');
        });
        foreach (['UPDATE', 'DELETE'] as $action) {
            DB::unprepared("CREATE TRIGGER plan_capitalizations_no_{$action} BEFORE {$action} ON plan_capitalizations BEGIN SELECT RAISE(ABORT, 'Savings evidence is immutable'); END");
        }
        DB::unprepared("CREATE TRIGGER plan_capitalizations_no_replace BEFORE INSERT ON plan_capitalizations WHEN EXISTS (SELECT 1 FROM plan_capitalizations WHERE id = NEW.id OR (savings_plan_id = NEW.savings_plan_id AND boundary_date = NEW.boundary_date)) BEGIN SELECT RAISE(ABORT, 'Savings evidence is immutable'); END");
        DB::unprepared("CREATE TRIGGER plan_waivers_immutable BEFORE UPDATE ON plan_waivers WHEN NEW.savings_plan_id IS NOT OLD.savings_plan_id OR NEW.approval_request_id IS NOT OLD.approval_request_id OR NEW.interest_cap IS NOT OLD.interest_cap OR NEW.principal_cap IS NOT OLD.principal_cap OR NEW.approved_at IS NOT OLD.approved_at OR NEW.expires_at IS NOT OLD.expires_at OR (OLD.used_at IS NOT NULL AND (NEW.used_at IS NOT OLD.used_at OR NEW.settlement_id IS NOT OLD.settlement_id)) OR (OLD.expired_at IS NOT NULL AND NEW.expired_at IS NOT OLD.expired_at) BEGIN SELECT RAISE(ABORT, 'Waiver evidence is immutable'); END");
        DB::unprepared("CREATE TRIGGER plan_waivers_no_delete BEFORE DELETE ON plan_waivers BEGIN SELECT RAISE(ABORT, 'Waiver evidence is immutable'); END");
        DB::unprepared("CREATE TRIGGER plan_waivers_no_replace BEFORE INSERT ON plan_waivers WHEN EXISTS (SELECT 1 FROM plan_waivers WHERE id = NEW.id OR approval_request_id = NEW.approval_request_id) BEGIN SELECT RAISE(ABORT, 'Waiver evidence is immutable'); END");
        Schema::table('savings_notices', function (Blueprint $table): void {
            $table->dropUnique(['savings_plan_id']);
            $table->string('event_key')->nullable()->unique();
        });
        foreach ($triggers as $trigger) {
            if (! DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger->name)->exists()) {
                DB::unprepared($trigger->sql);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Retained financial evidence cannot be safely removed by a rollback.
        throw new LogicException('Use a forward migration to change retained savings evidence.');
    }
};
