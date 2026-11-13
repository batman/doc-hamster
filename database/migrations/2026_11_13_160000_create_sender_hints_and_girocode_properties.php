<?php

declare(strict_types=1);

use App\Domain\Properties\Property;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * GiroCode and reading invoices (points 120, A236, A237): what the app learnt per sender (IBAN, where the amount
     * stands), the organisation's own IBAN per bank account (never proposed as payee) and the new default properties
     * (BIC, Kundennummer, Verwendungszweck, Skonto) for every existing organisation.
     */
    public function up(): void
    {
        Schema::create('sender_hints', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('sender_key', 120);
            $table->string('iban', 34)->nullable();
            $table->string('amount_keyword', 60)->nullable();
            $table->unsignedInteger('amount_page')->nullable();
            $table->json('amount_box')->nullable();
            $table->timestamps();
            $table->unique(['org_id', 'sender_key']);
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->string('iban', 34)->nullable();
        });

        $now = now();
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            $sort = (int) DB::table('properties')->where('org_id', $orgId)->max('sort') + 1;
            foreach (Property::DEFAULTS as $key => $type) {
                if (DB::table('properties')->where('org_id', $orgId)->where('key', $key)->exists()) {
                    continue;
                }
                DB::table('properties')->insert([
                    'id' => (string) Str::ulid(), 'org_id' => $orgId, 'key' => $key, 'name' => __('properties.defaults.'.$key),
                    'type' => $type, 'sort' => $sort++, 'active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('properties')->whereIn('key', ['customer_number', 'bic', 'payment_reference', 'discount_percent', 'discount_until'])->delete();
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropColumn('iban');
        });
        Schema::dropIfExists('sender_hints');
    }
};
