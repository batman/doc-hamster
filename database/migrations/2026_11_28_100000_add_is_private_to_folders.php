<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A69 (2026-11-17): a private folder (the owner's own papers, e.g. an ID copy) – only the owner sees it; the bank
// matching, the receipt pack, the export, the completeness view and every assistant leave it out.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', static function (Blueprint $table): void {
            $table->boolean('is_private')->default(false)->after('bank_matching');
        });
    }

    public function down(): void
    {
        Schema::table('folders', static function (Blueprint $table): void {
            $table->dropColumn('is_private');
        });
    }
};
