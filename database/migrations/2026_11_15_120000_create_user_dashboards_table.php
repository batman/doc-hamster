<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Points 176, A349-A353: Home is a dashboard of widgets, laid out per person (and organisation).
// layout = {"widgets":[{"type","size"}],"pins":[{"url","title","icon"}]}; photo_path = the person's own picture (private disk).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_dashboards', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('layout')->nullable();
            $table->string('photo_path', 200)->nullable();
            $table->timestamps();
            $table->unique(['org_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_dashboards');
    }
};
