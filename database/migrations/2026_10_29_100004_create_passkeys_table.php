<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WebAuthn credentials of owners and members (PLAN §11.1, D-006).
     * A credential ID may be up to 1023 bytes, too long for a unique index on
     * every supported database, so it is stored as base64url text and made
     * unique through its SHA-256 (`credential_hash`). The public key is raw
     * COSE bytes, so it is a BLOB – a utf8mb4 TEXT column rejects invalid UTF-8
     * in strict mode.
     */
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('credential_id');
            $table->char('credential_hash', 64)->unique();
            $table->binary('public_key');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->json('transports')->nullable();
            $table->char('aaguid', 36)->nullable();
            $table->boolean('backup_eligible')->default(false);
            $table->boolean('backup_state')->default(false);
            $table->string('name', 100);
            $table->dateTime('last_used_at')->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
