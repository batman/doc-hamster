<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Point 61/A79-A83: what text recognition did (server, browser, set-up, cron), kept 30 days. Never holds recognised text.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_log', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->string('source', 20);
            $table->string('event', 30);
            $table->unsignedTinyInteger('pass')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('chars')->nullable();
            $table->string('message', 300)->nullable();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['org_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_log');
    }
};
