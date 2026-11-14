<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// A358: what comes in has no folder until it is filed; the Posteingang = documents without a folder, and the Dokumente
// top level lists folders only. Nothing may disappear:
// - what waits in the Posteingang today (in "Dokumente allgemein" or without a folder, never opened, not marked done)
//   keeps waiting there: its folder becomes empty;
// - every other document without a folder (it showed on the Dokumente top level) goes into "Dokumente allgemein".
return new class extends Migration
{
    public function up(): void
    {
        $waiting = static fn ($query) => $query->whereNull('inbox_done_at')->whereNull('last_opened_at')->whereNull('deleted_at');

        DB::table('documents')->whereIn('folder_id', DB::table('folders')->where('is_default', true)->select('id'))
            ->where($waiting)->update(['folder_id' => null]);

        $orgs = DB::table('documents')->whereNull('folder_id')
            ->where(static fn ($query) => $query->whereNotNull('inbox_done_at')->orWhereNotNull('last_opened_at')->orWhereNotNull('deleted_at'))
            ->distinct()->pluck('org_id');
        foreach ($orgs as $orgId) {
            DB::table('documents')->where('org_id', $orgId)->whereNull('folder_id')
                ->where(static fn ($query) => $query->whereNotNull('inbox_done_at')->orWhereNotNull('last_opened_at')->orWhereNotNull('deleted_at'))
                ->update(['folder_id' => $this->defaultFolder((string) $orgId)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('documents')->whereNull('folder_id')->distinct()->pluck('org_id') as $orgId) {
            DB::table('documents')->where('org_id', $orgId)->whereNull('folder_id')->update(['folder_id' => $this->defaultFolder((string) $orgId)]);
        }
    }

    private function defaultFolder(string $orgId): string
    {
        $id = DB::table('folders')->where('org_id', $orgId)->where('is_default', true)->value('id');
        if (is_string($id)) {
            return $id;
        }
        $id = Str::lower((string) Str::ulid());
        DB::table('folders')->insert(['id' => $id, 'org_id' => $orgId, 'name' => 'Dokumente allgemein', 'is_default' => true, 'bank_matching' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
};
