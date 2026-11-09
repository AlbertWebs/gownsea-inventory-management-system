<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_order_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custom_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->string('category', 24)->index();
            $table->string('description', 180);
            $table->unsignedBigInteger('amount_minor');
            $table->date('incurred_on')->index();
            $table->timestamps();
            $table->index(['custom_order_id', 'incurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_order_costs');
    }
};
