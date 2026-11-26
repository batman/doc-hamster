<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Point 48 (2026-11-26): the Mail-Eingang – one mailbox per organisation (F26 a). docHamster fetches it on request
// (button in the Posteingang, and when the Posteingang opens) and files what allowed senders sent. The password is
// stored encrypted (D-007); the table is never part of a backup (like printers and access keys).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_inboxes', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('org_id')->unique();
            $table->ulid('user_id')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('host', 255)->default('');
            $table->unsignedInteger('port')->default(993);
            $table->string('security', 10)->default('ssl');
            $table->string('username', 255)->default('');
            $table->text('password')->nullable();
            $table->string('with_attachments', 12)->default('attachments');
            $table->string('without_attachments', 12)->default('mail');
            $table->text('senders')->nullable();
            $table->timestamp('last_fetch_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_inboxes');
    }
};
