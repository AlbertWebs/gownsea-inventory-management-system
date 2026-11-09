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
        Schema::create('chart_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 120);
            $table->string('account_type', 16)->index();
            $table->string('normal_balance', 8);
            $table->boolean('is_system')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('financial_journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->string('source_type', 120);
            $table->unsignedBigInteger('source_id');
            $table->string('source_reference', 64);
            $table->string('entry_type', 32);
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('financial_journal_entries')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->date('occurred_on')->index();
            $table->string('description', 255);
            $table->unsignedBigInteger('debit_total_minor');
            $table->unsignedBigInteger('credit_total_minor');
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'entry_type'], 'financial_journal_source_unique');
            $table->index(['location_id', 'occurred_on', 'id']);
        });

        Schema::create('financial_journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('financial_journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('chart_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->unique(['financial_journal_entry_id', 'line_number'], 'financial_journal_line_number_unique');
            $table->index(['chart_account_id', 'location_id']);
        });

        $now = now();
        DB::table('chart_accounts')->insert(collect([
            ['code' => '1000', 'name' => 'Cash on hand', 'account_type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '1010', 'name' => 'Bank account', 'account_type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '1020', 'name' => 'M-Pesa clearing', 'account_type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '1030', 'name' => 'Other payment clearing', 'account_type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '2000', 'name' => 'Customer deposit liability', 'account_type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '4000', 'name' => 'Hire service revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit'],
            ['code' => '4010', 'name' => 'Collected penalty revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit'],
            ['code' => '4020', 'name' => 'Custom order revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit'],
            ['code' => '4030', 'name' => 'Bulk product sales revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit'],
            ['code' => '6100', 'name' => 'Transport expense', 'account_type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '6110', 'name' => 'Labor expense', 'account_type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '6120', 'name' => 'Repairs expense', 'account_type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '6130', 'name' => 'SMS expense', 'account_type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '6140', 'name' => 'Delivery expense', 'account_type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '6190', 'name' => 'Other operating expense', 'account_type' => 'expense', 'normal_balance' => 'debit'],
        ])->map(fn (array $account): array => [...$account, 'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now])->all());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_journal_lines');
        Schema::dropIfExists('financial_journal_entries');
        Schema::dropIfExists('chart_accounts');
    }
};
