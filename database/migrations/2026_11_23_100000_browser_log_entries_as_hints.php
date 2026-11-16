<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Batch 16 (point 2): browser messages logged so far were all red "errors". Harmless noise (ResizeObserver loop warnings,
// browser extensions) goes; the rest becomes a "Hinweis" – no request or action of the person is known to have failed.
return new class extends Migration
{
    public function up(): void
    {
        $browser = DB::table('log_entries')->where('event', 'browser.error');
        (clone $browser)->where(static fn ($q) => $q->where('message', 'like', '%ResizeObserver loop%')
            ->orWhere('message', 'like', '%-extension://%')->orWhere('message', 'like', '%Transition was aborted%')
            ->orWhere('message', 'like', '%Transition was skipped%'))->delete();
        $browser->update(['level' => 'warning', 'event' => 'browser.hint']);
    }

    public function down(): void
    {
        // Nothing to undo: the entries stay hints.
    }
};
