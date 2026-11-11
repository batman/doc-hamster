<?php

declare(strict_types=1);

use App\Domain\Palette\PaletteColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * One shared colour palette per organisation (points 14-15), seeded with
     * the 20 note colours. Notes now store the hex like folders and tags do,
     * so their colour keys become hex values.
     */
    public function up(): void
    {
        Schema::create('palette_colors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 60);
            $table->char('hex', 7);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['org_id', 'position']);
        });

        $now = now();
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            $position = 0;
            foreach (PaletteColor::DEFAULTS as $key => $hex) {
                DB::table('palette_colors')->insert(['id' => (string) Str::ulid(), 'org_id' => $orgId, 'name' => __('home.colors.'.$key), 'hex' => $hex, 'position' => $position++, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        foreach (PaletteColor::DEFAULTS as $key => $hex) {
            DB::table('notes')->where('color', $key)->update(['color' => $hex]);
        }
    }

    public function down(): void
    {
        foreach (PaletteColor::DEFAULTS as $key => $hex) {
            DB::table('notes')->where('color', $hex)->update(['color' => $key]);
        }
        Schema::dropIfExists('palette_colors');
    }
};
