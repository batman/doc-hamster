<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Point 213 (F31): the X on Home's "Erste Schritte" card, remembered per person and organisation.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_dashboards', static function (Blueprint $table): void {
            $table->timestamp('first_steps_hidden_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_dashboards', static function (Blueprint $table): void {
            $table->dropColumn('first_steps_hidden_at');
        });
    }
};
