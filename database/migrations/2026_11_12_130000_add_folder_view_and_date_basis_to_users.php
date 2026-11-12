<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Points 98 and 108: the last display mode of the folder view (details, list, small, large) and the date
     * timeline and calendar go by ("doc" = receipt date, "upload" = upload date). Null means the defaults.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('folder_view', 16)->nullable();
            $table->string('receipt_dates', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['folder_view', 'receipt_dates']);
        });
    }
};
