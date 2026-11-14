<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A345: the OCR languages picked for one document under "Text neu erkennen" (tesseract form, e.g. "ell" or
     * "deu+ell"). Null = all installed and enabled languages (171.2). Kept for later re-reads of that document.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('ocr_langs', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('ocr_langs');
        });
    }
};
