<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Revolut module's own tables (prefix m_revolut_, D-041): its connection (API key and signing key encrypted) and the
// receipts it has brought in already.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_revolut_settings', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('org_id')->unique();
            $table->string('environment', 10)->default('live');
            $table->text('api_key')->nullable();
            $table->text('signing_private')->nullable();
            $table->text('signing_public')->nullable();
            $table->json('accounts')->nullable();
            $table->json('mapping')->nullable();
            $table->boolean('drafts')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('synced_until')->nullable();
            $table->timestamp('receipts_until')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });
        Schema::create('m_revolut_receipts', static function (Blueprint $table): void {
            $table->string('receipt_id', 64)->primary();
            $table->ulid('org_id')->index();
            $table->ulid('document_id');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_revolut_receipts');
        Schema::dropIfExists('m_revolut_settings');
    }
};
