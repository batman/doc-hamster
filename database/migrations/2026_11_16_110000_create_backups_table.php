<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point 206 (D-023): one row per "Backup herunterladen". Streamed ones are written at once (they tell Home when
     * the last backup was made); large ones are built by a background job into `path` and kept 24 hours. Neither
     * the ZIP password nor anything derived from it is stored.
     */
    public function up(): void
    {
        Schema::create('backups', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('options')->nullable();
            $table->unsignedBigInteger('estimate')->default(0);
            $table->unsignedBigInteger('size')->nullable();
            $table->string('path', 255)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->datetimes();

            $table->index(['org_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
