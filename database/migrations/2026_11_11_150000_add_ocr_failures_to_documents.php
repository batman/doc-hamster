<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Point 58/A63: failed browser reads (time limit, error) per document; after 3 it is marked failed and not handed out again.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('ocr_failures')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('documents', static function (Blueprint $table): void {
            $table->dropColumn('ocr_failures');
        });
    }
};
