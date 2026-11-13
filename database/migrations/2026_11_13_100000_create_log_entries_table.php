<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Point 115, A229: the logbooks "Allgemein" and "Updates" (Texterkennung keeps ocr_log). Kept 12 months (A228).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_entries', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->string('book', 20);
            $table->string('level', 10);
            $table->string('event', 64)->nullable();
            $table->string('message', 1000);
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->index(['org_id', 'created_at']);
            $table->index(['book', 'level', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_entries');
    }
};
