<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point 207: "Papierkorb leeren" with a safety net. An emptied document stays soft-deleted but leaves the Papierkorb;
     * `purge_after` is when the daily job deletes it for good. Until then the emptying can be undone. All documents of one
     * emptying share the same value (it groups them for "Leerung rückgängig machen").
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dateTime('purge_after')->nullable();
            $table->index(['org_id', 'purge_after']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['org_id', 'purge_after']);
            $table->dropColumn('purge_after');
        });
    }
};
