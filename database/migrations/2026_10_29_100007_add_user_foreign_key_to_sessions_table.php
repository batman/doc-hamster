<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sessions table (P1-1) was created before `users` existed. Now that it
     * does, link `sessions.user_id` to it: deleting a user ends all of their
     * sessions.
     */
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
    }
};
