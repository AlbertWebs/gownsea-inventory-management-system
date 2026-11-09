<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 48)->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 24)->index();
            $table->string('provider_reference')->nullable()->unique();
            $table->text('reason');
            $table->timestamp('refunded_at')->index();
            $table->timestamps();
            $table->index(['payment_id', 'refunded_at']);
            $table->index(['location_id', 'refunded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
