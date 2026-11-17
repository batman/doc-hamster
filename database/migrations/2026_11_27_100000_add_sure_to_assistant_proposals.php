<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A51 (2026-11-17): an assistant says whether it is sure of a proposal – the sure ones are taken over in one go, the
// unsure ones looked at one by one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_proposals', static function (Blueprint $table): void {
            $table->boolean('sure')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_proposals', static function (Blueprint $table): void {
            $table->dropColumn('sure');
        });
    }
};
