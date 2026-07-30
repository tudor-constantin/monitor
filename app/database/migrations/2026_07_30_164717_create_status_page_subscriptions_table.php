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
        Schema::create('status_page_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->boolean('subscribed_to_all')->default(true);
            $table->boolean('pending_subscribed_to_all')->default(true);
            $table->json('pending_monitor_ids')->nullable();
            $table->char('confirmation_token_hash', 64)->nullable()->unique();
            $table->timestamp('confirmation_requested_at')->nullable();
            $table->timestamp('verified_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['status_page_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_page_subscriptions');
    }
};
