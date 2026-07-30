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
        Schema::create('status_page_monitor', function (Blueprint $table) {
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('display_name', 100)->nullable();
            $table->unsignedInteger('position')->default(0);

            $table->primary(['status_page_id', 'monitor_id']);
            $table->index(['status_page_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_page_monitor');
    }
};
