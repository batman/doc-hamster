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
     * Tags per organisation (#32, PLAN §6.2). `source` is `user` for now,
     * `confidence` is for later rules. Existing organisations get the
     * pre-installed tags too.
     */
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 64);
            $table->datetimes();

            $table->unique(['org_id', 'name']);
        });

        Schema::create('document_tags', function (Blueprint $table): void {
            $table->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUlid('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->string('source', 16)->default('user');
            $table->unsignedTinyInteger('confidence')->nullable();

            $table->primary(['document_id', 'tag_id']);
        });

        $this->addTagsToExistingOrganizations();
    }

    public function addTagsToExistingOrganizations(): void
    {
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            foreach (['Rechnung', 'Quittung', 'Vertrag'] as $name) {
                DB::table('tags')->insert([
                    'id' => Str::lower((string) Str::ulid()),
                    'org_id' => $orgId,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_tags');
        Schema::dropIfExists('tags');
    }
};
