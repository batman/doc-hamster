<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Point 68: PDFs and readable images were marked "nicht möglich" although the browser reads them.
// They wait again; HEIC/HEIF and other files stay as they are.
return new class extends Migration
{
    public function up(): void
    {
        $readable = DB::table('document_files')
            ->where(static fn ($w) => $w->where('mime', 'application/pdf')
                ->orWhere(static fn ($i) => $i->where('mime', 'like', 'image/%')->whereNotIn('mime', ['image/heic', 'image/heif'])))
            ->select('document_id');

        DB::table('documents')->where('ocr_status', 'unavailable')->whereIn('id', $readable)
            ->update(['ocr_status' => 'pending', 'ocr_pass' => null, 'ocr_failures' => 0]);
    }

    public function down(): void
    {
        // Nothing to undo: waiting documents are simply read.
    }
};
