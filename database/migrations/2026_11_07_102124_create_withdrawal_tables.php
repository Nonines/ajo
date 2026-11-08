<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('label');
            $table->text('details');
            $table->string('origin')->default('real');
            $table->timestamps();
        });
        DB::unprepared("CREATE TABLE approval_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_type VARCHAR NOT NULL, subject_id INTEGER NOT NULL,
            action VARCHAR NOT NULL, payload TEXT NOT NULL,
            requested_by INTEGER NOT NULL REFERENCES staff(id) ON DELETE RESTRICT,
            decided_by INTEGER REFERENCES staff(id) ON DELETE RESTRICT,
            state VARCHAR NOT NULL DEFAULT 'pending', reason TEXT NOT NULL, decision_reason TEXT,
            expires_at DATETIME NOT NULL, decided_at DATETIME, applied_at DATETIME, evidence TEXT,
            created_at DATETIME, updated_at DATETIME,
            CHECK (decided_by IS NULL OR decided_by != requested_by)
        )");
        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->index(['subject_type', 'subject_id']);
            $table->index('expires_at');
        });
        DB::unprepared("CREATE TRIGGER approvals_distinct_insert BEFORE INSERT ON approval_requests WHEN NEW.decided_by = NEW.requested_by BEGIN SELECT RAISE(ABORT, 'Requester and decider must differ'); END");
        DB::unprepared("CREATE TRIGGER approvals_distinct_update BEFORE UPDATE ON approval_requests WHEN NEW.decided_by = NEW.requested_by BEGIN SELECT RAISE(ABORT, 'Requester and decider must differ'); END");
        DB::unprepared("CREATE TRIGGER approvals_snapshot BEFORE UPDATE OF subject_type, subject_id, action, payload, requested_by, expires_at, created_at ON approval_requests BEGIN SELECT RAISE(ABORT, 'Approval proposal is immutable'); END");
        Schema::create('withdrawal_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('saved_destination_id')->constrained()->restrictOnDelete();
            $table->string('request_key')->unique();
            $table->string('reference')->unique();
            $table->string('origin')->default('real');
            $table->text('destination');
            $table->bigInteger('principal');
            $table->bigInteger('reserved_fee');
            $table->bigInteger('threshold');
            $table->string('state')->default('requested');
            $table->foreignId('reviewed_by')->nullable()->constrained('staff')->restrictOnDelete();
            $table->foreignId('approval_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reservation_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->foreignId('principal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->foreignId('return_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->bigInteger('accounted_fee')->nullable();
            $table->bigInteger('verified_fee')->nullable();
            $table->unsignedInteger('fee_revision')->default(0);
            $table->string('provider_id')->nullable();
            $table->unique(['origin', 'provider_id']);
            $table->string('provider_status')->nullable();
            $table->text('verification')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_dispatch_at')->nullable();
            $table->timestamp('provider_approved_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('next_verify_at')->nullable()->index();
            $table->timestamps();
        });
        DB::unprepared("CREATE TRIGGER withdrawals_snapshot BEFORE UPDATE OF user_id, saved_destination_id, request_key, reference, origin, destination, principal, reserved_fee, threshold, expires_at, created_at ON withdrawal_requests BEGIN SELECT RAISE(ABORT, 'Withdrawal reservation is immutable'); END");
        DB::unprepared("CREATE TRIGGER destinations_snapshot BEFORE UPDATE OF user_id, details, origin ON saved_destinations BEGIN SELECT RAISE(ABORT, 'Saved destination is immutable'); END");
        Schema::table('payment_notices', function (Blueprint $table): void {
            $table->foreignId('deposit_id')->nullable()->change();
            $table->foreignId('withdrawal_request_id')->nullable()->constrained()->restrictOnDelete();
        });
        // Provider identities are unique within each adapter origin, allowing isolated simulation evidence.
        Schema::table('deposits', function (Blueprint $table): void {
            $table->string('origin')->default('real');
            $table->dropUnique(['provider_id']);
            $table->unique(['origin', 'provider_id']);
        });
        Schema::table('payment_reversals', function (Blueprint $table): void {
            $table->string('origin')->default('real');
            $table->dropUnique(['provider_refund_id']);
            $table->unique(['origin', 'provider_refund_id']);
        });
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->string('origin')->default('real');
        });
        Schema::create('payment_simulations', function (Blueprint $table): void {
            $table->id();
            $table->morphs('subject');
            $table->string('reference')->unique();
            $table->string('refund_id')->nullable()->unique();
            $table->text('evidence');
            $table->timestamps();
        });
        DB::unprepared("CREATE TRIGGER deposits_origin BEFORE UPDATE OF origin ON deposits BEGIN SELECT RAISE(ABORT, 'Payment adapter origin is immutable'); END");
        DB::unprepared("CREATE TRIGGER reversal_origin BEFORE UPDATE OF origin ON payment_reversals BEGIN SELECT RAISE(ABORT, 'Reversal adapter origin is immutable'); END");
        DB::unprepared("CREATE TRIGGER webhook_origin BEFORE UPDATE OF origin ON webhook_events BEGIN SELECT RAISE(ABORT, 'Webhook adapter origin is immutable'); END");
        DB::unprepared("CREATE TRIGGER simulation_identity BEFORE UPDATE OF subject_type, subject_id, reference ON payment_simulations BEGIN SELECT RAISE(ABORT, 'Simulation identity is immutable'); END");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_simulations');
        DB::unprepared('DROP TRIGGER IF EXISTS deposits_origin');
        DB::unprepared('DROP TRIGGER IF EXISTS reversal_origin');
        DB::unprepared('DROP TRIGGER IF EXISTS webhook_origin');
        Schema::table('payment_notices', fn (Blueprint $table) => $table->dropConstrainedForeignId('withdrawal_request_id'));
        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('saved_destinations');
        Schema::table('deposits', function (Blueprint $table): void {
            $table->dropUnique(['origin', 'provider_id']);
            $table->dropColumn('origin');
            $table->unique('provider_id');
        });
        Schema::table('payment_reversals', function (Blueprint $table): void {
            $table->dropUnique(['origin', 'provider_refund_id']);
            $table->dropColumn('origin');
            $table->unique('provider_refund_id');
        });
        Schema::table('webhook_events', fn (Blueprint $table) => $table->dropColumn('origin'));
    }
};
