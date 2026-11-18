<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A82 (2026-11-18): a log entry may carry a long text below its short line – e.g. the protocol of an upload with every
// file name; shown folded in the Logbuch, copyable, and readable by assistants with the right "Logbuch lesen".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_entries', static function (Blueprint $table): void {
            $table->mediumText('body')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('log_entries', static function (Blueprint $table): void {
            $table->dropColumn('body');
        });
    }
};
