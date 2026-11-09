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
            'code' => '1100', 'name' => 'Customer receivables', 'account_type' => 'asset', 'normal_balance' => 'debit',
            'is_system' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::create('hire_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 64)->unique();
            $table->foreignId('hire_id')->unique()->constrained()->restrictOnDelete();
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

        Schema::create('hire_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hire_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('hire_item_id')->constrained('hire_items')->restrictOnDelete();
            $table->string('description', 200);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_rate_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();
            $table->unique(['hire_invoice_id', 'hire_item_id']);
        });

        Schema::create('hire_invoice_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hire_invoice_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 120);
            $table->unsignedBigInteger('source_id');
            $table->string('kind', 24);
            $table->unsignedBigInteger('amount_minor');
            $table->date('allocated_on')->index();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source_type', 'source_id'], 'hire_invoice_allocation_source_unique');
            $table->index(['hire_invoice_id', 'allocated_on', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hire_invoice_allocations');
        Schema::dropIfExists('hire_invoice_lines');
        Schema::dropIfExists('hire_invoices');
        DB::table('chart_accounts')->where('code', '1100')->delete();
    }
};
