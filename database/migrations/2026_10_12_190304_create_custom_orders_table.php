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
        Schema::create('custom_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 48)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 28)->default('new')->index();
            $table->string('item_type', 120);
            $table->string('size', 48)->nullable();
            $table->string('color', 64)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->json('measurements')->nullable();
            $table->text('design_specs')->nullable();
            $table->text('special_requirements')->nullable();
            $table->unsignedBigInteger('agreed_total_minor')->default(0);
            $table->unsignedBigInteger('deposit_required_minor')->default(0);
            $table->date('order_date')->index();
            $table->date('expected_completion_date')->index();
            $table->string('priority', 16)->default('normal')->index();
            $table->string('delivery_method', 16)->default('collection');
            $table->string('delivery_address')->nullable();
            $table->string('courier')->nullable();
            $table->string('tracking_number')->nullable()->index();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'status', 'expected_completion_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_orders');
    }
};
