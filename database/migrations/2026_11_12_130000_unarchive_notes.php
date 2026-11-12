<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Notes have no archive any more (point 92): archived notes come back
     * onto the board, so nothing is lost. The column archived_at stays.
     */
    public function up(): void
    {
        DB::table('notes')->whereNotNull('archived_at')->update(['archived_at' => null]);
    }

    public function down(): void
    {
        // Which notes were archived is not kept.
    }
};
