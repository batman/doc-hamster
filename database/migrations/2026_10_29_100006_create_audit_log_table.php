<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail (PLAN §6.4, §11.3). Deliberately **no foreign
     * keys**: deleting a user, device or organisation must neither block nor
     * rewrite the history, so the IDs are kept as plain ULIDs. No
     * `updated_at` – rows are never changed. IPs only as HMAC.
     */
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('org_id')->nullable();
            $table->ulid('user_id')->nullable()->index();
            $table->ulid('device_id')->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->ulid('subject_id')->nullable();
            $table->json('meta_json')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->dateTime('created_at');

            $table->index(['org_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
