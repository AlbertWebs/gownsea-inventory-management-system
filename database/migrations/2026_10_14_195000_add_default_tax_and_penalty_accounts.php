<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('chart_accounts')->insertOrIgnore(collect([
            ['code' => '1195', 'name' => 'Refund clearing', 'account_type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '2200', 'name' => 'VAT payable', 'account_type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '4090', 'name' => 'Penalty waivers', 'account_type' => 'revenue', 'normal_balance' => 'debit'],
        ])->map(fn (array $account): array => [...$account, 'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now])->all());
    }

    public function down(): void
    {
        DB::table('chart_accounts')->whereIn('code', ['1195', '2200', '4090'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('financial_journal_lines')
                ->whereColumn('financial_journal_lines.chart_account_id', 'chart_accounts.id'))
            ->delete();
    }
};
