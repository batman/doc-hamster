<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Every table of a module starts with m_<key>_ – "Daten des Moduls auch löschen" removes exactly these.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_beispiel_notes', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('document_id')->index();
            $table->text('note');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_beispiel_notes');
    }
};
