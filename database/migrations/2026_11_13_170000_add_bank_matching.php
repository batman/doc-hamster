<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bank module (points 129, A267–A274): the counterparty's IBAN of a booking, "kein Beleg nötig" with a reason,
     * matches with a state (suggested by the matcher / confirmed by a person), score and reasons, the paid_at date a
     * confirmed link set (removed again on unlink only if it is still that one), and the pairs a person rejected.
     * `matches` already is n:m (unique per pair): a booking can carry several documents and a document several bookings.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('counterparty_iban', 34)->nullable();
            $table->boolean('no_receipt')->default(false);
            $table->string('no_receipt_reason', 255)->nullable();
        });

        Schema::table('matches', function (Blueprint $table): void {
            $table->string('state', 10)->default('confirmed');
            $table->unsignedSmallInteger('score')->nullable();
            $table->json('reasons')->nullable();
            $table->date('paid_at_set')->nullable();
            $table->index(['org_id', 'state']);
        });

        Schema::create('match_rejections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->datetimes();

            $table->unique(['transaction_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_rejections');
        Schema::table('matches', function (Blueprint $table): void {
            // MySQL/MariaDB: the org_id foreign key may be using this index – give it its own one first.
            $table->index('org_id');
        });
        Schema::table('matches', function (Blueprint $table): void {
            $table->dropIndex(['org_id', 'state']);
            $table->dropColumn(['state', 'score', 'reasons', 'paid_at_set']);
        });
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['counterparty_iban', 'no_receipt', 'no_receipt_reason']);
        });
    }
};
