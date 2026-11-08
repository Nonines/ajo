<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->dateTime('current_at');
            $table->dateTime('history_starts_at');
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('target_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('staff')->restrictOnDelete();
            $table->text('failure')->nullable();
            $table->unsignedInteger('history_step')->default(0);
            $table->dateTime('seeded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_state');
    }
};
