<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time codes to connect a phone (PLAN §11.1 point 2): shown on a device
     * that is already signed in, as QR code and short code, valid 10 minutes,
     * usable once. Only the code's SHA-256 is stored.
     */
    public function up(): void
    {
        Schema::create('device_pairings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->foreignUlid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_pairings');
    }
};
