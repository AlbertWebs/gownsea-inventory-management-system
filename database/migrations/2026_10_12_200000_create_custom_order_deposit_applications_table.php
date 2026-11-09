<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_order_deposit_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custom_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('applied_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('applied_at')->index();
            $table->timestamps();
            $table->index(['custom_order_id', 'applied_at']);
            $table->index(['payment_id', 'applied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_order_deposit_applications');
    }
};
