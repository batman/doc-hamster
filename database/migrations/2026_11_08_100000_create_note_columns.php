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
     * Notes as a board like Trello (Go of 2026-11-08): columns with a name
     * and an order, notes in a column with an order, an archive, and tags.
     * Every organisation with notes starts with one column "Notizen" holding
     * them in their old reading order (top left first). pos_x/pos_y/width
     * stay, so an older version still finds them.
     */
    public function up(): void
    {
        Schema::create('note_columns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('notes', function (Blueprint $table): void {
            $table->foreignUlid('column_id')->nullable()->constrained('note_columns')->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamp('archived_at')->nullable();
        });

        Schema::create('note_tags', function (Blueprint $table): void {
            $table->foreignUlid('note_id')->constrained('notes')->cascadeOnDelete();
            $table->foreignUlid('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->primary(['note_id', 'tag_id']);
        });

        $now = now();
        foreach (DB::table('notes')->distinct()->pluck('org_id') as $orgId) {
            $columnId = (string) Str::ulid();
            DB::table('note_columns')->insert(['id' => $columnId, 'org_id' => $orgId, 'name' => 'Notizen', 'sort' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $notes = DB::table('notes')->where('org_id', $orgId)
                ->orderByRaw('pos_y is null')->orderBy('pos_y')->orderBy('pos_x')->orderBy('created_at')->pluck('id');
            foreach ($notes as $i => $id) {
                DB::table('notes')->where('id', $id)->update(['column_id' => $columnId, 'sort' => $i]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('note_tags');
        Schema::table('notes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('column_id');
            $table->dropColumn(['sort', 'archived_at']);
        });
        Schema::dropIfExists('note_columns');
    }
};
