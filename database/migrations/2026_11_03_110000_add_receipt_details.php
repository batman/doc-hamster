<?php

declare(strict_types=1);

use App\Domain\Documents\Tag;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Design round 2: a colour per tag (any colour, Q-29), the receipt view's
     * history (last opened, last downloaded), deleting into a trash, and a
     * stored thumbnail per file so the list never loads the original.
     */
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table): void {
            $table->char('color', 7)->default(Tag::PALETTE[0]);
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->dateTime('last_opened_at')->nullable();
            $table->dateTime('last_downloaded_at')->nullable();
            $table->softDeletes();
        });
        Schema::table('document_files', function (Blueprint $table): void {
            $table->string('thumb_path')->nullable();
        });

        $this->colourExistingTags();
    }

    /** Existing tags take the colours of the palette in turn, per organisation. */
    public function colourExistingTags(): void
    {
        foreach (DB::table('tags')->orderBy('org_id')->orderBy('name')->get(['id', 'org_id'])->groupBy('org_id') as $tags) {
            foreach ($tags->values() as $n => $tag) {
                DB::table('tags')->where('id', $tag->id)->update(['color' => Tag::PALETTE[$n % count(Tag::PALETTE)]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('document_files', fn (Blueprint $table) => $table->dropColumn('thumb_path'));
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn(['last_opened_at', 'last_downloaded_at']);
        });
        Schema::table('tags', fn (Blueprint $table) => $table->dropColumn('color'));
    }
};
