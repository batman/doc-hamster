<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time invitation links/QR codes to join an organisation (PLAN §11.1).
     * Tax advisor accounts are created directly by the owner, not invited
     * (D-012). Only the SHA-256 of the token is stored.
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('role', 16);
            $table->char('token_hash', 64)->unique();
            $table->string('email', 254)->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->foreignUlid('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
