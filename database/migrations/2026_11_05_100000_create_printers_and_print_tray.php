<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The printer "Hamster": a computer that sends what is printed on it.
     * It is linked once with a one-time code from the download and then uses
     * its own token; only hashes are stored. Printed documents wait in the
     * print tray (Druckerablage) until someone takes them over as receipts.
     */
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('pairing_hash', 64)->nullable()->unique();
            $table->dateTime('pairing_expires_at')->nullable();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->datetimes();

            $table->index(['org_id', 'user_id']);
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->boolean('in_print_tray')->default(false)->after('note');
            $table->index(['org_id', 'in_print_tray']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['org_id', 'in_print_tray']);
            $table->dropColumn('in_print_tray');
        });
        Schema::dropIfExists('printers');
    }
};
