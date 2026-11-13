<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A243: OCR corrections learned per sender (original → corrected, how often), offered on other documents of that sender.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_learned', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('sender_key', 100);
            $table->string('original', 100);
            $table->string('corrected', 100);
            $table->unsignedInteger('count')->default(1);
            $table->timestamps();
            $table->unique(['org_id', 'sender_key', 'original', 'corrected']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_learned');
    }
};
