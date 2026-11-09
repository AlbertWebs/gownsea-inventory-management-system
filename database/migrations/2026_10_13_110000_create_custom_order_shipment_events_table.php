<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_order_shipment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custom_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->string('event_type', 32)->index();
            $table->string('provider_reference', 120)->nullable();
            $table->text('details')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['custom_order_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_order_shipment_events');
    }
};
