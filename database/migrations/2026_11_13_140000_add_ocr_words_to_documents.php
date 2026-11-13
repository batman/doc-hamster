<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Points 119/120.5: word positions of the recognised text, corrections by position, and the low-priority re-scan flag (F17).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', static function (Blueprint $table): void {
            $table->longText('ocr_words')->nullable();
            $table->json('ocr_fixes')->nullable();
            $table->boolean('ocr_rescan')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('documents', static function (Blueprint $table): void {
            $table->dropColumn(['ocr_words', 'ocr_fixes', 'ocr_rescan']);
        });
    }
};
