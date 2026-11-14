<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Points 165.6, A343: how an ambiguous numeric date (03/04/2025) was read – dmy, mdy or ymd – on the value, and
     * per sender as the default reading next time.
     */
    public function up(): void
    {
        Schema::table('document_properties', function (Blueprint $table): void {
            $table->string('date_order', 3)->nullable();
        });
        Schema::table('sender_hints', function (Blueprint $table): void {
            $table->string('date_order', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sender_hints', function (Blueprint $table): void {
            $table->dropColumn('date_order');
        });
        Schema::table('document_properties', function (Blueprint $table): void {
            $table->dropColumn('date_order');
        });
    }
};
