<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Recognised text of a receipt, for the search (OCR add-on, D-010). */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->longText('ocr_text')->nullable();
            // pending | done | failed | unavailable; null = never tried.
            $table->string('ocr_status', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['ocr_text', 'ocr_status']);
        });
    }
};
