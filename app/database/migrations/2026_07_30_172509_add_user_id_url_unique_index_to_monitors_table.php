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
            $table->char('url_hash', 64)
                ->storedAs('sha2(`url`, 256)')
                ->after('url');
            $table->unique(['user_id', 'url_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'url_hash']);
            $table->dropColumn('url_hash');
        });
    }
};
