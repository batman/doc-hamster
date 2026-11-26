<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A126 (2026-11-26): the Mail-Eingang can answer allowed senders ("Vielen Dank, Ihre Dokumente sind angekommen.") –
// through the outgoing server (SMTP) of the same mailbox, with the same user and password.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_inboxes', static function (Blueprint $table): void {
            $table->boolean('reply_enabled')->default(false);
            $table->text('reply_text')->nullable();
            $table->string('smtp_host', 255)->default('');
            $table->unsignedInteger('smtp_port')->default(465);
            $table->string('smtp_security', 10)->default('ssl');
        });
    }

    public function down(): void
    {
        Schema::table('mail_inboxes', static function (Blueprint $table): void {
            $table->dropColumn(['reply_enabled', 'reply_text', 'smtp_host', 'smtp_port', 'smtp_security']);
        });
    }
};
