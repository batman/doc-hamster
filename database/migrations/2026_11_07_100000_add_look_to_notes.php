<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sticky notes as a board: a title, colour, opacity, lock and the place
     * on the board. All optional, so an older version simply ignores them.
     */
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->string('title', 100)->nullable();
            $table->string('color', 16)->nullable();
            $table->unsignedTinyInteger('opacity')->nullable();
            $table->boolean('locked')->default(false);
            $table->integer('pos_x')->nullable();
            $table->integer('pos_y')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->dropColumn(['title', 'color', 'opacity', 'locked', 'pos_x', 'pos_y']);
        });
    }
};
