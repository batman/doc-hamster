<?php

declare(strict_types=1);

use App\Jobs\Local\BackfillReceiptDates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point 214: the Belegdatum (doc_date) and where it comes from – confirmed|recognised|exif|pdf|upload, null = not
     * worked out yet. Existing documents get theirs once in the background (BackfillReceiptDates on the local queue,
     * the cron worker runs it) – the server needs no console for that.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('doc_date_source', 12)->nullable();
            $table->index(['org_id', 'doc_date']);
        });

        if (DB::table('documents')->exists()) {
            try {
                BackfillReceiptDates::dispatch();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['org_id', 'doc_date']);
            $table->dropColumn('doc_date_source');
        });
    }
};
