<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point 186.4: who read the document's text – 'browser' or 'server' – and when, for the detail's Verlauf
     * ("Text erkannt am … (Server)"). Null for texts read before, and for text documents written in the app.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('ocr_engine', 10)->nullable();
            $table->timestamp('ocr_read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['ocr_engine', 'ocr_read_at']);
        });
    }
};
