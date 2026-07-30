<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('initial_check_id')
                ->nullable()
                ->constrained('monitor_checks')
                ->nullOnDelete();
            $table->foreignId('recovery_check_id')
                ->nullable()
                ->constrained('monitor_checks')
                ->nullOnDelete();
            $table->text('cause')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('open_monitor_id')
                ->nullable()
                ->virtualAs('if(`resolved_at` is null, `monitor_id`, null)');
            $table->timestamps();

            $table->unique('open_monitor_id');
            $table->index(['monitor_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
