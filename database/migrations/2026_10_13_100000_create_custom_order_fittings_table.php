<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_order_fittings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custom_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('scheduled_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('scheduled')->index();
            $table->timestamp('appointment_at')->index();
            $table->json('measurements')->nullable();
            $table->text('outcome')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['custom_order_id', 'appointment_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_order_fittings');
    }
};
