<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point 236 "Dokumentgröße optimieren": per file whether the optimiser has looked at it (`optimize`: queued, small,
     * skip, done, original), when it was queued or done (`optimize_at`) and how big it was before (`original_size`, for
     * "Platz gespart"). A document that holds the original of an optimised one names it in `original_of`; it never
     * takes part in bank matching or invoice reading.
     */
    public function up(): void
    {
        Schema::table('document_files', function (Blueprint $table): void {
            $table->string('optimize', 16)->nullable();
            $table->unsignedBigInteger('original_size')->nullable();
            $table->dateTime('optimize_at')->nullable();
            $table->index(['org_id', 'optimize']);
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->ulid('original_of')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['original_of']);
            $table->dropColumn('original_of');
        });
        Schema::table('document_files', function (Blueprint $table): void {
            $table->dropIndex(['org_id', 'optimize']);
            $table->dropColumn(['optimize', 'original_size', 'optimize_at']);
        });
    }
};
