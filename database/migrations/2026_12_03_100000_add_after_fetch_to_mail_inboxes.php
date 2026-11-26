<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A127 (2026-11-26): what happens to a taken mail in the mailbox – moved into "Verarbeitet" (as before) or deleted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_inboxes', static function (Blueprint $table): void {
            $table->string('after_fetch', 10)->default('move');
        });
    }

    public function down(): void
    {
        Schema::table('mail_inboxes', static function (Blueprint $table): void {
            $table->dropColumn('after_fetch');
        });
    }
};
