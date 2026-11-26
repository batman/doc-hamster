<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Point 46 (A113, 2026-11-26): the background script on the computer says "still here" every few minutes – the
// Drucker page shows when it was last connected and warns when a computer has been silent for a long time.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', static function (Blueprint $table): void {
            $table->timestamp('last_seen_at')->nullable()->after('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('printers', static function (Blueprint $table): void {
            $table->dropColumn('last_seen_at');
        });
    }
};
