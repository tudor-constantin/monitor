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
        Schema::create('status_page_subscription_monitor', function (Blueprint $table) {
            $table->ulid('status_page_subscription_id');
            $table->foreign(
                'status_page_subscription_id',
                'subscription_monitor_subscription_fk',
            )
                ->references('id')
                ->on('status_page_subscriptions')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('monitor_id');
            $table->foreign('monitor_id', 'subscription_monitor_monitor_fk')
                ->references('id')
                ->on('monitors')
                ->cascadeOnDelete();

            $table->primary(['status_page_subscription_id', 'monitor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_page_subscription_monitor');
    }
};
