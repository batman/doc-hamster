<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point 147: a note per booking. Point 148 (A322–A325): "Dauerbeleg" – a document that belongs to every booking of
     * one counterparty (IBAN, else the normalised name) with one amount and direction; matches remember the rule that
     * made them, so confirming a suggestion with another amount moves the rule's amount (A323).
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->text('note')->nullable();
        });

        Schema::create('standing_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('counterparty_iban', 34)->nullable();
            $table->string('counterparty_name', 255)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('direction', 3);
            $table->string('interval', 12)->nullable();
            $table->boolean('active')->default(true);
            $table->datetimes();

            $table->index(['org_id', 'active']);
        });

        Schema::table('matches', function (Blueprint $table): void {
            $table->foreignUlid('standing_id')->nullable()->constrained('standing_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('standing_id');
        });
        Schema::dropIfExists('standing_documents');
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};
