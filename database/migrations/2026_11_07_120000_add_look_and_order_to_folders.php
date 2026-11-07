<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Folders get a colour, a symbol from a fixed set and an order chosen by
     * drag and drop (PO, 2026-11-07). The default folder is now called
     * "Belege allgemein", and every organisation gets "Rechnungen" next to it.
     * Only nullable columns, so the code on main still runs on this database.
     */
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table): void {
            $table->string('color', 7)->nullable()->after('name');
            $table->string('icon', 40)->nullable()->after('color');
            $table->unsignedInteger('position')->nullable()->after('icon');
        });

        DB::table('folders')->where('is_default', true)->update(['name' => 'Belege allgemein']);

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            $names = DB::table('folders')->where('org_id', $orgId)->whereNull('parent_id')->pluck('name')
                ->map(static fn (string $name): string => mb_strtolower($name));
            if ($names->contains('rechnungen')) {
                continue;
            }
            DB::table('folders')->insert([
                'id' => Str::lower((string) Str::ulid()),
                'org_id' => $orgId,
                'name' => 'Rechnungen',
                'icon' => 'file-invoice',
                'is_default' => false,
                'bank_matching' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table): void {
            $table->dropColumn(['color', 'icon', 'position']);
        });
    }
};
