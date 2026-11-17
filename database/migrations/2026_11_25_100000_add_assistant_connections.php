<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// D-040 (2026-11-17): assistants connect by clicks – Claude's "Konnektor hinzufügen" registers itself (OAuth dynamic
// client registration) and a person allows it on docHamster's consent page. A connection is an access key with the
// client it belongs to, a short-lived access token (key_hash, expires_at) and a rotating refresh token.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('client_id', 40)->unique();
            $table->char('secret_hash', 64)->nullable();
            $table->string('name', 100);
            $table->json('redirect_uris');
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::table('access_keys', static function (Blueprint $table): void {
            $table->string('client_id', 40)->nullable()->after('scope');
            $table->char('refresh_hash', 64)->nullable()->unique()->after('key_prefix');
            $table->dateTime('expires_at')->nullable()->after('refresh_hash');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::table('access_keys', static function (Blueprint $table): void {
            $table->dropIndex(['client_id']);
            $table->dropUnique(['refresh_hash']);
            $table->dropColumn(['client_id', 'refresh_hash', 'expires_at']);
        });
        Schema::dropIfExists('oauth_clients');
    }
};
