<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Scan Station also prints (PHONE, issue #37): a request is either a scan
     * or a print of one stored file. Additive and nullable, so going back to an
     * older version keeps working (old code only sees scans).
     */
    public function up(): void
    {
        Schema::table('scan_requests', function (Blueprint $table): void {
            $table->string('kind', 10)->default('scan')->after('station_id');
            $table->ulid('file_id')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('scan_requests', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'file_id']);
        });
    }
};
