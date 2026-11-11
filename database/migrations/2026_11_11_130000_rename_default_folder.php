<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// "Belege" became "Dokumente" (point 31): the default folder's stored name follows, unless someone renamed it.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('folders')->where('is_default', true)->where('name', 'Belege allgemein')->update(['name' => 'Dokumente allgemein']);
    }

    public function down(): void
    {
        DB::table('folders')->where('is_default', true)->where('name', 'Dokumente allgemein')->update(['name' => 'Belege allgemein']);
    }
};
