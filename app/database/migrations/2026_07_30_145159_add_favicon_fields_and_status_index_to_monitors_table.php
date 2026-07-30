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
        Schema::table('monitors', function (Blueprint $table) {
            $table->string('favicon_path')->nullable()->after('url');
            $table->timestamp('favicon_fetched_at')->nullable()->after('favicon_path');
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->dropColumn(['favicon_path', 'favicon_fetched_at']);
        });
    }
};
