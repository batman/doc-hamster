<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point 238 (A414/A416): rotation and crop of a file's pages, non-destructive – the stored file never changes.
     * JSON per page number, e.g. {"1":{"r":90,"c":[0.1,0.05,0.8,0.9]}} (App\Domain\Documents\PageTransform).
     */
    public function up(): void
    {
        Schema::table('document_files', function (Blueprint $table): void {
            $table->text('transform')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_files', function (Blueprint $table): void {
            $table->dropColumn('transform');
        });
    }
};
