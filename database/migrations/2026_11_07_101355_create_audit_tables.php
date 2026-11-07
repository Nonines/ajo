<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('log_name')->default('audit');
            $table->string('description');
            $table->string('event');
            $table->nullableMorphs('subject');
            $table->nullableMorphs('causer');
            $table->text('properties');
            $table->text('attribute_changes');
            $table->string('key_version');
            $table->string('previous_hash', 64);
            $table->string('hash', 64);
            $table->timestamp('created_at')->index();
            $table->timestamp('updated_at');
        });
        Schema::create('audit_head', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->string('hash', 64);
            $table->unsignedBigInteger('floor_sequence')->default(0);
            $table->string('floor_hash', 64);
        });
        DB::table('audit_head')->insert(['id' => 1, 'sequence' => 0,
            'hash' => str_repeat('0', 64), 'floor_sequence' => 0, 'floor_hash' => str_repeat('0', 64)]);
        Schema::create('audit_customer_references', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('last_sequence');
        });
        Schema::create('audit_archives', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('first_sequence')->unique();
            $table->unsignedBigInteger('last_sequence')->unique();
            $table->string('previous_hash', 64);
            $table->string('last_hash', 64);
            $table->string('path');
            $table->string('file_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
            $table->timestamp('purged_at')->nullable();
            $table->string('key_version');
            $table->string('seal', 64);
        });
        DB::unprepared("CREATE TRIGGER activity_log_no_update BEFORE UPDATE ON activity_log BEGIN SELECT RAISE(ABORT, 'Audit history is immutable'); END");
        DB::unprepared("CREATE TRIGGER activity_log_no_delete BEFORE DELETE ON activity_log WHEN audit_maintenance() != 1 BEGIN SELECT RAISE(ABORT, 'Audit history is immutable'); END");
        DB::unprepared("CREATE TRIGGER activity_log_no_replace BEFORE INSERT ON activity_log WHEN NEW.id != (SELECT sequence + 1 FROM audit_head WHERE id = 1) OR NEW.previous_hash != (SELECT hash FROM audit_head WHERE id = 1) OR EXISTS (SELECT 1 FROM activity_log WHERE id = NEW.id) BEGIN SELECT RAISE(ABORT, 'Audit sequence is immutable'); END");
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('audit_customer_references');
        Schema::dropIfExists('audit_archives');
        Schema::dropIfExists('audit_head');
    }
};
