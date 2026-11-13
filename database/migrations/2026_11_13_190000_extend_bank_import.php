<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bank import, second round (points 136–144, A286–A312): every optional field a bank delivers (A289), the columns
     * nobody mapped as "weitere Angaben" (A290) and the raw row (sign fixes later, 143), the bank's booking ID and
     * balance for dedupe (A294), refunds hanging on their original (A298), the imported periods (A296), the
     * "kein Beleg nötig" reasons with the remembered counterparties (A286, A287) and MCC → tag (A300). Accounts get
     * a source key (Revolut "Account" + currency, A299). Accounts named after a Revolut file are renamed (142).
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('booking_id', 100)->nullable();
            $table->date('value_date')->nullable();
            $table->text('purpose2')->nullable();
            $table->string('kind', 60)->nullable();
            $table->string('reference', 255)->nullable();
            $table->string('creditor_id', 40)->nullable();
            $table->string('counterparty_bic', 11)->nullable();
            $table->bigInteger('orig_amount_minor')->nullable();
            $table->char('orig_currency', 3)->nullable();
            $table->bigInteger('fee_minor')->nullable();
            $table->char('fee_currency', 3)->nullable();
            $table->bigInteger('balance_minor')->nullable();
            $table->string('category', 100)->nullable();
            $table->char('card_last4', 4)->nullable();
            $table->string('bank_state', 30)->nullable();
            $table->string('sub_account', 100)->nullable();
            $table->string('related_id', 100)->nullable();
            $table->char('mcc', 4)->nullable();
            $table->json('extra')->nullable();
            $table->json('raw')->nullable();
            $table->ulid('parent_id')->nullable()->index();
            $table->ulid('no_receipt_reason_id')->nullable();
            $table->ulid('suggested_reason_id')->nullable();
            $table->index(['bank_account_id', 'booking_id']);
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->string('source_key', 150)->nullable();
        });

        Schema::create('bank_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedInteger('rows')->default(0);
            $table->datetimes();
            $table->index(['bank_account_id', 'date_from']);
        });

        Schema::create('no_receipt_reasons', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->datetimes();
            $table->index(['org_id', 'sort']);
        });

        Schema::create('no_receipt_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('counterparty_key', 255);
            $table->foreignUlid('reason_id')->constrained('no_receipt_reasons')->cascadeOnDelete();
            $table->datetimes();
            $table->unique(['org_id', 'counterparty_key']);
        });

        Schema::create('bank_mcc_tags', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('mcc', 4);
            $table->string('tag', 60);
            $table->datetimes();
            $table->unique(['org_id', 'mcc']);
        });

        // 142: an account that carries the name of a Revolut export file gets a speaking name.
        foreach (DB::table('bank_accounts')->get(['id', 'name', 'currency']) as $account) {
            $name = (string) $account->name;
            if (preg_match('/^(account-statement|transaction-statement|transactions?[_-]|revolut)/i', $name) === 1 && ! str_starts_with($name, 'Revolut ')) {
                DB::table('bank_accounts')->where('id', $account->id)->update(['name' => 'Revolut '.$account->currency]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_mcc_tags');
        Schema::dropIfExists('no_receipt_rules');
        Schema::dropIfExists('no_receipt_reasons');
        Schema::dropIfExists('bank_imports');
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropColumn('source_key');
        });
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex(['bank_account_id', 'booking_id']);
            $table->dropColumn(['booking_id', 'value_date', 'purpose2', 'kind', 'reference', 'creditor_id', 'counterparty_bic', 'orig_amount_minor', 'orig_currency',
                'fee_minor', 'fee_currency', 'balance_minor', 'category', 'card_last4', 'bank_state', 'sub_account', 'related_id', 'mcc', 'extra', 'raw',
                'no_receipt_reason_id', 'suggested_reason_id', 'parent_id']);
        });
    }
};
