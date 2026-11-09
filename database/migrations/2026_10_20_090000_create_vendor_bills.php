<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_bills', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('vendor_name', 160)->index();
            $table->string('invoice_number', 120);
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('expense_account_code', 16)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->date('invoice_date')->index();
            $table->date('due_date')->index();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['vendor_name', 'invoice_number']);
            $table->index(['location_id', 'due_date']);
        });

        Schema::create('vendor_bill_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_bill_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 36)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 24);
            $table->string('payment_reference', 120)->nullable();
            $table->date('paid_on')->index();
            $table->timestamps();
            $table->index(['vendor_bill_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_bill_payments');
        Schema::dropIfExists('vendor_bills');
    }
};
