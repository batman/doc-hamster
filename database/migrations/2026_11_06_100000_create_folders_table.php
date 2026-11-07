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
     * Folders per organisation (issue #77). A folder holds receipts and other
     * folders; a receipt is in exactly one folder. Every organisation has the
     * folder "Belege" (`is_default`, cannot be deleted, new receipts land
     * there). `bank_matching` says whether the bank matching may use the
     * receipts of a folder: on for "Belege", off for new folders (PO, 2026-11-06).
     * Deleting a folder is done in the controller (contents move up);
     * `nullOnDelete` is only the safety net.
     */
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->ulid('parent_id')->nullable();
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            $table->boolean('bank_matching')->default(false);
            $table->datetimes();

            $table->index(['org_id', 'parent_id']);
        });

        Schema::table('folders', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('folders')->nullOnDelete();
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->ulid('folder_id')->nullable()->after('org_id');
            $table->foreign('folder_id')->references('id')->on('folders')->nullOnDelete();
            $table->index(['org_id', 'folder_id']);
        });

        // Existing organisations get "Belege" and all their receipts go into it.
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            $folderId = Str::lower((string) Str::ulid());
            DB::table('folders')->insert([
                'id' => $folderId,
                'org_id' => $orgId,
                'name' => 'Belege',
                'is_default' => true,
                'bank_matching' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('documents')->where('org_id', $orgId)->update(['folder_id' => $folderId]);
        }
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign(['folder_id']);
            $table->dropIndex(['org_id', 'folder_id']);
            $table->dropColumn('folder_id');
        });
        Schema::dropIfExists('folders');
    }
};
