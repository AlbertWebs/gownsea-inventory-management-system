<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bulk_stock_balances', function (Blueprint $table): void {
            $table->unsignedBigInteger('inventory_value_minor')->default(0)->after('on_hand');
            $table->boolean('valuation_complete')->default(false)->after('inventory_value_minor');
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('cost_minor')->default(0)->after('quantity');
            $table->unsignedBigInteger('valuation_total_minor')->nullable()->after('cost_minor');
        });
        Schema::table('bulk_sale_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('cogs_minor')->default(0)->after('line_total_minor');
        });
        Schema::table('bulk_sale_return_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('cogs_minor')->default(0)->after('line_total_minor');
        });

        $now = now();
        DB::table('chart_accounts')->insertOrIgnore(collect([
            ['code' => '1200', 'name' => 'Bulk inventory', 'account_type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '2100', 'name' => 'Supplier payables', 'account_type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '3000', 'name' => 'Opening inventory equity', 'account_type' => 'equity', 'normal_balance' => 'credit'],
            ['code' => '4900', 'name' => 'Inventory count gains', 'account_type' => 'revenue', 'normal_balance' => 'credit'],
            ['code' => '5000', 'name' => 'Cost of goods sold', 'account_type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '6200', 'name' => 'Inventory shrinkage and write-offs', 'account_type' => 'expense', 'normal_balance' => 'debit'],
        ])->map(fn (array $account): array => [...$account, 'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now])->all());

        $balances = DB::table('bulk_stock_balances as b')->join('products as p', 'p.id', '=', 'b.product_id')
            ->where('p.tracking_type', 'bulk')->select('b.id', 'b.product_id', 'b.location_id', 'b.on_hand', 'p.purchase_cost_minor')->orderBy('b.id')->get();
        $accounts = DB::table('chart_accounts')->whereIn('code', ['1200', '3000'])->pluck('id', 'code');
        foreach ($balances as $balance) {
            $value = (int) $balance->on_hand * (int) $balance->purchase_cost_minor;
            DB::table('bulk_stock_balances')->where('id', $balance->id)->update([
                'inventory_value_minor' => $value,
                'valuation_complete' => (int) $balance->on_hand === 0 || (int) $balance->purchase_cost_minor > 0,
            ]);
            if ($value === 0) {
                continue;
            }

            $reference = 'OPENING-BULK-'.$balance->id;
            $entryId = DB::table('financial_journal_entries')->insertGetId([
                'reference' => 'GJ-OPEN-BULK-'.$balance->id,
                'source_type' => 'bulk-opening-balance', 'source_id' => $balance->id,
                'source_reference' => $reference, 'entry_type' => 'opening_balance',
                'location_id' => $balance->location_id, 'posted_by' => null, 'occurred_on' => $now->toDateString(),
                'description' => 'Opening valuation for bulk inventory balance '.$balance->id,
                'debit_total_minor' => $value, 'credit_total_minor' => $value, 'posted_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('financial_journal_lines')->insert([
                ['financial_journal_entry_id' => $entryId, 'chart_account_id' => $accounts['1200'], 'location_id' => $balance->location_id, 'line_number' => 1, 'debit_minor' => $value, 'credit_minor' => 0, 'description' => 'Opening bulk inventory', 'created_at' => $now, 'updated_at' => $now],
                ['financial_journal_entry_id' => $entryId, 'chart_account_id' => $accounts['3000'], 'location_id' => $balance->location_id, 'line_number' => 2, 'debit_minor' => 0, 'credit_minor' => $value, 'description' => 'Opening inventory equity', 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $openingEntryIds = DB::table('financial_journal_entries')->where('source_type', 'bulk-opening-balance')->pluck('id');
        DB::table('financial_journal_lines')->whereIn('financial_journal_entry_id', $openingEntryIds)->delete();
        DB::table('financial_journal_entries')->whereIn('id', $openingEntryIds)->delete();
        Schema::table('bulk_sale_return_lines', fn (Blueprint $table) => $table->dropColumn('cogs_minor'));
        Schema::table('bulk_sale_lines', fn (Blueprint $table) => $table->dropColumn('cogs_minor'));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn(['cost_minor', 'valuation_total_minor']));
        Schema::table('bulk_stock_balances', fn (Blueprint $table) => $table->dropColumn(['inventory_value_minor', 'valuation_complete']));
        DB::table('chart_accounts')->whereIn('code', ['1200', '2100', '3000', '4900', '5000', '6200'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('financial_journal_lines')->whereColumn('chart_account_id', 'chart_accounts.id'))
            ->delete();
    }
};
