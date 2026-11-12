<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Point 69 (A106/A109): each log entry names the file it read (type, size, pages) and, for the browser, which browser.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_log', static function (Blueprint $table): void {
            $table->string('file_type', 10)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedSmallInteger('pages')->nullable();
            $table->string('agent', 60)->nullable();
            $table->index(['document_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::table('ocr_log', static function (Blueprint $table): void {
            $table->dropIndex(['document_id', 'event']);
            $table->dropColumn(['file_type', 'size', 'pages', 'agent']);
        });
    }
};
