<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipts (MVP subset of PLAN §6.2): one document with one or more files.
     * Files live outside the web root; `path` is relative to the private disk.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->date('doc_date')->nullable();
            $table->text('note')->nullable();
            $table->datetimes();

            $table->index(['org_id', 'created_at']);
        });

        Schema::create('document_files', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('kind', 16)->default('original');
            $table->unsignedSmallInteger('page_no')->default(1);
            $table->string('path', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_files');
        Schema::dropIfExists('documents');
    }
};
