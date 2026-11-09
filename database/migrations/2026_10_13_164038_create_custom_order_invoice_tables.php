<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('chart_accounts')->insertOrIgnore([
            'code' => '2010', 'name' => 'Custom order advances', 'account_type' => 'liability', 'normal_balance' => 'credit',
            'is_system' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::create('custom_order_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 64)->unique();
            $table->foreignId('custom_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('currency', 3)->default('KES');
            $table->unsignedBigInteger('total_minor');
            $table->date('issued_on')->index();
            $table->date('due_on')->nullable()->index();
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->index(['location_id', 'issued_on', 'id']);
        });

        Schema::create('custom_order_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custom_order_invoice_id')->constrained()->restrictOnDelete();
            $table->string('description', 240);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();
            $table->index(['custom_order_invoice_id', 'id']);
        });

        Schema::create('custom_order_invoice_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custom_order_invoice_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 120);
            $table->unsignedBigInteger('source_id');
            $table->string('kind', 24);
            $table->unsignedBigInteger('amount_minor');
            $table->date('allocated_on')->index();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source_type', 'source_id'], 'custom_order_invoice_allocation_source_unique');
            $table->index(['custom_order_invoice_id', 'allocated_on', 'id'], 'custom_order_invoice_allocations_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_order_invoice_allocations');
        Schema::dropIfExists('custom_order_invoice_lines');
        Schema::dropIfExists('custom_order_invoices');
        DB::table('chart_accounts')->where('code', '2010')->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('financial_journal_lines')->whereColumn('chart_account_id', 'chart_accounts.id');
        })->delete();
    }
};
