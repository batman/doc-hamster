<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// D-040 (2026-11-17): an assistant changes nothing itself – it files proposals (Empfehlungskacheln) that the owner takes
// over ("Übernehmen") or not ("Ablehnen") under Vorschläge. The same proposal is filed only once while it is open.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_proposals', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->json('payload');
            $table->text('reason')->nullable();
            $table->char('fingerprint', 64);
            $table->string('status', 12)->default('open');
            $table->text('result')->nullable();
            $table->string('assistant', 100)->nullable();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->index(['org_id', 'status', 'kind']);
            $table->index(['org_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_proposals');
    }
};
