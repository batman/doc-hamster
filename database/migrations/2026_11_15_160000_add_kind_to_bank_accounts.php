<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Points 189, 190: accounts set up by hand – their kind (business, private, cash) and, for a cash account, the
     * opening balance; bookings typed in by hand carry the source "manual" (imported ones "import").
     */
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->string('kind', 20)->default('business');
            $table->bigInteger('opening_balance_minor')->default(0);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('source', 20)->default('import');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'opening_balance_minor']);
        });
    }
};
