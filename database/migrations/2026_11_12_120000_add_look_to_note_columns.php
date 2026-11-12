<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Points 66, A100, A101, A103: a column's background colour, the default colour of its new notes and its note order.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('note_columns', static function (Blueprint $table): void {
            $table->string('color', 7)->nullable();
            $table->string('note_color', 7)->nullable();
            $table->string('note_sort', 10)->default('manual');
        });
    }

    public function down(): void
    {
        Schema::table('note_columns', static function (Blueprint $table): void {
            $table->dropColumn(['color', 'note_color', 'note_sort']);
        });
    }
};
