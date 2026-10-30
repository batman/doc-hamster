<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bank statements (MVP subset of PLAN §6.3): one account per organisation
     * for now, transactions from CSV import, manual links to receipts.
     * Money in minor units; `fingerprint` keeps re-imports from doubling rows.
     */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 150);
            $table->char('currency', 3)->default('EUR');
            $table->datetimes();
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->date('booking_date');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('EUR');
            $table->string('counterparty', 255)->nullable();
            $table->text('purpose')->nullable();
            $table->char('fingerprint', 64);
            $table->datetimes();

            $table->unique(['bank_account_id', 'fingerprint']);
            $table->index(['org_id', 'booking_date']);
        });

        Schema::create('matches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetimes();

            $table->unique(['transaction_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('bank_accounts');
    }
};
