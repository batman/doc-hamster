<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Points 151/152: where a document came in – upload|printer|mail|phone|api; null = upload (older rows too).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('documents', 'source')) {
            return;
        }
        Schema::table('documents', static function (Blueprint $table): void {
            $table->string('source', 16)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('documents', 'source')) {
            Schema::table('documents', static function (Blueprint $table): void {
                $table->dropColumn('source');
            });
        }
    }
};
