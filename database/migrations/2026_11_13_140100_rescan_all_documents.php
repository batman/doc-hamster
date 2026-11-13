<?php

declare(strict_types=1);

use App\Jobs\Local\RecognizeText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// F17 / point 119: every document is read once more for its word positions, behind new uploads (ocr_rescan, low
// queue). Read documents keep their text and status until the new run replaces them; failed ones (e.g. the old
// browser "doc.destroy is not a function" error) and "nicht möglich" wait again with their failures reset.
return new class extends Migration
{
    public function up(): void
    {
        $live = static fn () => DB::table('documents')->whereNull('deleted_at');
        $live()->where('ocr_status', 'done')->update(['ocr_rescan' => true, 'ocr_failures' => 0]);
        $live()->whereIn('ocr_status', ['failed', 'unavailable'])
            ->update(['ocr_status' => 'pending', 'ocr_pass' => null, 'ocr_failures' => 0, 'ocr_rescan' => true]);

        // The server reads them through the low queue; the browser hands them out after all new documents.
        // On the sync driver this would read everything inside the migration – there the browser or `documents:ocr` does it.
        if (config('queue.default') !== 'sync') {
            RecognizeText::queueRescans();
        }
    }

    public function down(): void
    {
        DB::table('documents')->update(['ocr_rescan' => false]);
    }
};
