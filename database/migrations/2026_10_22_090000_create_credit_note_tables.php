<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 64)->unique();
            $table->foreignId('payment_refund_id')->unique()->constrained()->restrictOnDelete();
            $table->string('invoice_type', 64);
            $table->unsignedBigInteger('invoice_id');
            $table->string('invoice_number', 64);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3)->default('KES');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->date('issued_on')->index();
            $table->text('reason');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['invoice_type', 'invoice_id']);
            $table->index(['location_id', 'issued_on']);
        });

        Schema::create('credit_note_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credit_note_id')->constrained()->restrictOnDelete();
            $table->string('description', 240);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('net_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->string('tax_code', 48)->nullable();
            $table->string('tax_treatment', 24)->nullable();
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->string('tax_account_code', 16)->nullable();
            $table->string('revenue_account_code', 16)->nullable();
            $table->timestamps();
            $table->index(['credit_note_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
    }
};
