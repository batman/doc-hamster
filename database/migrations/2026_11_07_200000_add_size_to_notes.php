<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notes get a size (dragged at the corner) and their lists move into the
     * text: "[Milch, #Brot]" (Go of 2026-11-07). An old checklist is written
     * at the end of the text, so nothing is lost, also on an older version.
     */
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
        });

        foreach (DB::table('notes')->whereNotNull('checklist')->get(['id', 'body', 'checklist']) as $note) {
            /** @var list<array{text: string, done: bool}> $items */
            $items = json_decode((string) $note->checklist, true) ?: [];
            // Commas and brackets would end an entry or the list.
            $entries = array_map(static fn (array $item): string => ($item['done'] ? '#' : '').str_replace([',', '[', ']'], [';', '(', ')'], $item['text']), $items);
            $body = trim((string) $note->body);
            if ($entries !== []) {
                $body = trim($body."\n[".implode(', ', $entries).']');
            }
            DB::table('notes')->where('id', $note->id)->update(['body' => $body === '' ? null : $body, 'checklist' => null]);
        }
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->dropColumn(['width', 'height']);
        });
    }
};
