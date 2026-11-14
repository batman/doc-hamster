<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Posteingang (point 153): when a document was marked "Erledigt" there. Null = it may still wait in the inbox
     * (it also leaves when it is opened or moved out of "Dokumente allgemein"). Everything already there counts as
     * handled, except what still waited in the old Druckerablage.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->timestamp('inbox_done_at')->nullable();
        });
        DB::table('documents')->where('in_print_tray', false)->update(['inbox_done_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('inbox_done_at');
        });
    }
};
