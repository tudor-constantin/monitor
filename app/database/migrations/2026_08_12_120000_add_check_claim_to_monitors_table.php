<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            // Identifies which run of CheckMonitor currently owns this monitor.
            // A run that finds the token changed under it has been superseded
            // and must not write its result.
            $table->uuid('check_claim_token')->nullable()->after('next_check_at');
            $table->timestamp('check_claim_expires_at')->nullable()->after('check_claim_token');

            // The scheduling slot whose result was last recorded. Copies of one
            // dispatch all carry that dispatch's slot, so this is what tells a
            // second copy its result has already been written.
            $table->timestamp('last_check_epoch')->nullable()->after('check_claim_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['check_claim_token', 'check_claim_expires_at', 'last_check_epoch']);
        });
    }
};
