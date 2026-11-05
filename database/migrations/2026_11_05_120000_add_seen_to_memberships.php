<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a person last opened each area (e.g. "print-tray"), per organisation.
     * The menu badges count what is newer. Null = never opened.
     */
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->json('seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropColumn('seen_at');
        });
    }
};
