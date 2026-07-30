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
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('url');
            $table->string('method', 10)->default('GET');
            $table->unsignedSmallInteger('expected_status_code')->default(200);
            $table->unsignedInteger('interval_seconds')->default(300);
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->string('status')->default('pending');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['is_active', 'next_check_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitors');
    }
};
