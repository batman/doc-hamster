<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point 219: the time of a booking when the bank delivers one ("H:i", local time; null = none) next to the
     * booking date, and the time printed on a receipt (read by OCR) next to the Belegdatum – both for matching.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('booked_time', 5)->nullable();
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('doc_time', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('booked_time');
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('doc_time');
        });
    }
};
