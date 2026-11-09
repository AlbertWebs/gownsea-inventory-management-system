<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedSmallInteger('useful_life_months')->default(36);
        });
        Schema::table('physical_assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('acquisition_cost_minor')->default(0);
            $table->date('acquired_on')->nullable()->index();
            $table->unsignedBigInteger('accumulated_depreciation_minor')->default(0);
        });
        Schema::create('vendor_bill_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_bill_id')->constrained()->restrictOnDelete();
            $table->foreignId('physical_asset_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('acquisition_cost_minor');
            $table->timestamps();
        });
        Schema::create('asset_depreciations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('physical_asset_id')->constrained()->restrictOnDelete();
            $table->char('period', 7);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('accumulated_minor');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['physical_asset_id', 'period']);
        });

        $now = now();
        DB::table('chart_accounts')->insertOrIgnore(collect([
            ['code' => '1500', 'name' => 'Serialized hire assets', 'account_type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '1510', 'name' => 'Accumulated depreciation · hire assets', 'account_type' => 'asset', 'normal_balance' => 'credit'],
            ['code' => '6400', 'name' => 'Depreciation expense · hire assets', 'account_type' => 'expense', 'normal_balance' => 'debit'],
        ])->map(fn (array $account): array => [...$account, 'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now])->all());

        $accountIds = DB::table('chart_accounts')->whereIn('code', ['1500', '3000'])->pluck('id', 'code');
        $assets = DB::table('physical_assets as a')->join('products as p', 'p.id', '=', 'a.product_id')
            ->where('p.tracking_type', 'serialized')->select('a.id', 'a.home_location_id', 'a.created_at', 'p.purchase_cost_minor')->orderBy('a.id')->get();
        foreach ($assets as $asset) {
            $cost = (int) $asset->purchase_cost_minor;
            DB::table('physical_assets')->where('id', $asset->id)->update([
                'acquisition_cost_minor' => $cost,
                'acquired_on' => $now->toDateString(),
            ]);
            if ($cost < 1) {
                continue;
            }
            $postedAt = $now;
            $entryId = DB::table('financial_journal_entries')->insertGetId([
                'reference' => 'GJ-OPEN-ASSET-'.$asset->id, 'source_type' => 'serialized-opening-balance', 'source_id' => $asset->id,
                'source_reference' => 'OPENING-ASSET-'.$asset->id, 'entry_type' => 'opening_balance',
                'location_id' => $asset->home_location_id, 'posted_by' => null, 'occurred_on' => substr($postedAt, 0, 10),
                'description' => 'Opening value for serialized hire asset '.$asset->id,
                'debit_total_minor' => $cost, 'credit_total_minor' => $cost, 'posted_at' => $postedAt,
                'created_at' => $postedAt, 'updated_at' => $postedAt,
            ]);
            DB::table('financial_journal_lines')->insert([
                ['financial_journal_entry_id' => $entryId, 'chart_account_id' => $accountIds['1500'], 'location_id' => $asset->home_location_id, 'line_number' => 1, 'debit_minor' => $cost, 'credit_minor' => 0, 'description' => 'Serialized hire asset', 'created_at' => $postedAt, 'updated_at' => $postedAt],
                ['financial_journal_entry_id' => $entryId, 'chart_account_id' => $accountIds['3000'], 'location_id' => $asset->home_location_id, 'line_number' => 2, 'debit_minor' => 0, 'credit_minor' => $cost, 'description' => 'Opening inventory equity', 'created_at' => $postedAt, 'updated_at' => $postedAt],
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('financial_journal_entries')->where('source_type', 'serialized-opening-balance')->pluck('id');
        DB::table('financial_journal_lines')->whereIn('financial_journal_entry_id', $ids)->delete();
        DB::table('financial_journal_entries')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('asset_depreciations');
        Schema::dropIfExists('vendor_bill_assets');
        Schema::table('physical_assets', fn (Blueprint $table) => $table->dropColumn(['acquisition_cost_minor', 'acquired_on', 'accumulated_depreciation_minor']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('useful_life_months'));
        DB::table('chart_accounts')->whereIn('code', ['1500', '1510', '6400'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('financial_journal_lines')->whereColumn('chart_account_id', 'chart_accounts.id'))
            ->delete();
    }
};
