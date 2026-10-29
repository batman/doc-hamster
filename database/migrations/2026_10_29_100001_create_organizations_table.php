<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An organisation is one separate data space – household, company or club
     * (PLAN §2, D-011). Every domain table points here via `org_id`.
     * `retention_days` null = keep documents until the owner deletes them.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->json('settings_json')->nullable();
            $table->unsignedSmallInteger('retention_days')->nullable();
            $table->unsignedSmallInteger('trash_days')->default(30);
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
