<?php

declare(strict_types=1);

use App\Jobs\Local\RecognizeText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Point 130/A275: no more browser passes 1 and 2. A document read only in pass 1 or 2 counts as read (ocr_pass 3) and
// is read once more at full resolution behind new uploads (ocr_rescan), keeping its text until then.
return new class extends Migration
{
    public function up(): void
    {
        $count = DB::table('documents')->whereNull('deleted_at')->where('ocr_status', 'done')->whereIn('ocr_pass', [1, 2])
            ->update(['ocr_pass' => 3, 'ocr_rescan' => true, 'ocr_failures' => 0]);

        if ($count > 0 && config('queue.default') !== 'sync') {
            RecognizeText::queueRescans();
        }
    }

    public function down(): void {}
};
