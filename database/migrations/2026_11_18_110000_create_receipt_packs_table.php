<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point 211 (D-018): every receipt pack is frozen – its PDF stays as built, with a version number per period
     * (v1, v2 … – a change means a new pack) and the time it was made. `summary` keeps the key figures of the cover.
     * documents.in_receipt_pack (A38): a document without a booking that still goes into the pack ("Belege ohne Buchung").
     */
    public function up(): void
    {
        Schema::create('receipt_packs', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('period_label', 32);
            $table->unsignedInteger('version');
            $table->json('account_ids');
            $table->string('status', 16);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('pages')->nullable();
            $table->json('summary')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->datetimes();

            $table->unique(['org_id', 'period_label', 'version']);
            $table->index(['org_id', 'created_at']);
        });

        Schema::table('documents', static function (Blueprint $table): void {
            $table->boolean('in_receipt_pack')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('documents', static function (Blueprint $table): void {
            $table->dropColumn('in_receipt_pack');
        });
        Schema::dropIfExists('receipt_packs');
    }
};
