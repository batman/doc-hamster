<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Point 228: notes are always fully opaque; the opacity setting and its stored values go.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', static function (Blueprint $table): void {
            $table->dropColumn('opacity');
        });
    }

    public function down(): void
    {
        Schema::table('notes', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('opacity')->nullable();
        });
    }
};
