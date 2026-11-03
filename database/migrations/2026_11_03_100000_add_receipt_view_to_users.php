<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The view of the receipt list each person chose (ui-redesign point 11).
     * Null means the default, "details".
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('receipt_view', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('receipt_view');
        });
    }
};
