<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('currency')->default('NGN');
            $table->bigInteger('balance')->default(0);
        });
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->string('operation_key')->unique();
            $table->string('fingerprint', 64);
            $table->string('currency')->default('NGN');
            $table->date('effective_date')->index();
            $table->timestamp('posted_at');
            $table->string('description');
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('journals')->restrictOnDelete();
        });
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->bigInteger('amount');
            $table->index(['account_id', 'journal_id']);
        });
        // Protect history at the database boundary as well as through Eloquent write guards.
        foreach (['journals', 'ledger_entries'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $action) {
                DB::unprepared("CREATE TRIGGER {$table}_no_{$action} BEFORE {$action} ON {$table} BEGIN SELECT RAISE(ABORT, 'Ledger history is immutable'); END");
            }
        }
        // REPLACE can skip delete triggers, so reject collisions before it removes history.
        DB::unprepared("CREATE TRIGGER ledger_entries_no_replace BEFORE INSERT ON ledger_entries WHEN EXISTS (SELECT 1 FROM ledger_entries WHERE id = NEW.id) BEGIN SELECT RAISE(ABORT, 'Ledger history is immutable'); END");
        DB::unprepared("CREATE TRIGGER journals_no_replace BEFORE INSERT ON journals WHEN EXISTS (SELECT 1 FROM journals WHERE id = NEW.id OR operation_key = NEW.operation_key OR reverses_id = NEW.reverses_id) BEGIN SELECT RAISE(ABORT, 'Ledger history is immutable'); END");
        DB::unprepared("CREATE TRIGGER ledger_accounts_identity BEFORE UPDATE OF code, user_id, currency, type ON ledger_accounts BEGIN SELECT RAISE(ABORT, 'Account identity is immutable'); END");
        foreach (['cash_at_gateway' => 'asset', 'interest_expense' => 'expense', 'penalty_income' => 'income', 'gateway_fees' => 'expense', 'fee_recovery_income' => 'income', 'withholding_tax_payable' => 'liability', 'reversal_write_off_expense' => 'expense'] as $name => $type) {
            DB::table('ledger_accounts')->insert(['code' => 'house:'.$name, 'type' => $type]);
        }
        foreach (['deposits_in_transit' => 'asset', 'payouts_in_transit' => 'liability'] as $name => $type) {
            DB::table('ledger_accounts')->insert(['code' => 'clearing:'.$name, 'type' => $type]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('journals');
        Schema::dropIfExists('ledger_accounts');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_active'));
    }
};
