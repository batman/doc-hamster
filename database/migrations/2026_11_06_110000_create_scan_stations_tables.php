<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Scan Station (issue #77): a small program on the home network that
     * connects OUT to this server, takes scan requests and sends back the
     * pages. Linked once with a one-time code, then it uses its own token;
     * only hashes are stored (same pattern as the printer "Hamster").
     * A scan request is one click on "Scannen": queued, running, done, failed.
     */
    public function up(): void
    {
        Schema::create('scan_stations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('pairing_hash', 64)->nullable()->unique();
            $table->dateTime('pairing_expires_at')->nullable();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->string('printer_name', 200)->nullable();
            $table->boolean('printer_feeder')->default(false);
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->datetimes();

            $table->index('org_id');
        });

        Schema::create('scan_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('station_id')->constrained('scan_stations')->cascadeOnDelete();
            $table->string('source', 10);
            $table->string('color', 10);
            $table->unsignedSmallInteger('dpi');
            $table->string('status', 10)->default('queued');
            $table->string('error', 60)->nullable();
            $table->unsignedSmallInteger('pages')->default(0);
            $table->boolean('acknowledged')->default(false);
            $table->datetimes();

            $table->index(['station_id', 'status']);
            $table->index(['org_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_requests');
        Schema::dropIfExists('scan_stations');
    }
};
