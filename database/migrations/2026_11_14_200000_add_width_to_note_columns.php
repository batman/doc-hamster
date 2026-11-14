<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Point 179, A355: each column of the notes board has one of three widths: narrow, normal or wide.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('note_columns', static function (Blueprint $table): void {
            $table->string('width', 6)->default('normal');
        });
    }

    public function down(): void
    {
        Schema::table('note_columns', static function (Blueprint $table): void {
            $table->dropColumn('width');
        });
    }
};
