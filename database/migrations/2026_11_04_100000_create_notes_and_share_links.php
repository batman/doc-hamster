<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sticky notes on the home page (private, or shown to everyone, editable
     * by others or not) with a checklist and linked receipts; and links that
     * hand out one receipt without login until they expire or are revoked.
     */
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->json('checklist')->nullable();
            $table->boolean('shared')->default(false);
            $table->boolean('editable')->default(false);
            $table->datetimes();

            $table->index(['org_id', 'user_id']);
        });

        Schema::create('note_documents', function (Blueprint $table): void {
            $table->foreignUlid('note_id')->constrained('notes')->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();

            $table->primary(['note_id', 'document_id']);
        });

        Schema::create('share_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
        Schema::dropIfExists('note_documents');
        Schema::dropIfExists('notes');
    }
};
