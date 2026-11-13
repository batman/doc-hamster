<?php

declare(strict_types=1);

use App\Domain\Properties\Property;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Document properties (points 122.1, 122.2, A244-A249): typed fields per organisation (Rechnungsdatum,
     * Rechnungsbetrag, IBAN …) and one value per property and document. Every existing organisation gets the
     * default properties; users get a list of property columns for the details view (A244).
     */
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('key', 40)->nullable();
            $table->string('name', 60);
            $table->string('type', 12);
            $table->json('options')->nullable();
            $table->json('tag_ids')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['org_id', 'key']);
            $table->index(['org_id', 'sort']);
        });

        Schema::create('document_properties', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('value_text', 500)->nullable();
            $table->bigInteger('value_int')->nullable();
            $table->date('value_date')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('state', 10)->default('confirmed');
            $table->unsignedInteger('page')->nullable();
            $table->json('box')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'property_id']);
            $table->index(['org_id', 'property_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->json('document_columns')->nullable();
        });

        $now = now();
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            $sort = 0;
            foreach (Property::DEFAULTS as $key => $type) {
                DB::table('properties')->insert([
                    'id' => (string) Str::ulid(), 'org_id' => $orgId, 'key' => $key, 'name' => __('properties.defaults.'.$key),
                    'type' => $type, 'sort' => $sort++, 'active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('document_columns');
        });
        Schema::dropIfExists('document_properties');
        Schema::dropIfExists('properties');
    }
};
