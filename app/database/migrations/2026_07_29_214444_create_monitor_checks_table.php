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
        Schema::create('monitor_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedBigInteger('response_size_bytes')->nullable();
            $table->string('resolved_ip', 45)->nullable();
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['monitor_id', 'checked_at']);
            $table->index(['monitor_id', 'status', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitor_checks');
    }
};
