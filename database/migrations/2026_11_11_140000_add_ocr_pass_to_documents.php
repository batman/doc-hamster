<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Point 56: the browser reads in three passes (fast, medium, full resolution); the server sets 3 at once.
// null = not read yet, or read before passes existed (then final).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('ocr_pass')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', static function (Blueprint $table): void {
            $table->dropColumn('ocr_pass');
        });
    }
};
