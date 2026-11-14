<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Point 172.2: the translation of a document's recognised text, one per target language. */
    public function up(): void
    {
        Schema::create('document_translations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('from_lang', 8);
            $table->string('to_lang', 8);
            $table->longText('text');
            $table->timestamps();
            $table->unique(['document_id', 'to_lang']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_translations');
    }
};
