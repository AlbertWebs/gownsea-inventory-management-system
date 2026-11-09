<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_inventory_counts', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->string('approval_key', 64)->nullable()->unique();
            $table->string('cancellation_key', 64)->nullable()->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('awaiting_approval')->index();
            $table->unsignedBigInteger('expected_count')->default(0);
            $table->unsignedBigInteger('counted_count')->default(0);
            $table->text('notes')->nullable();
            $table->text('approval_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bulk_inventory_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bulk_inventory_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('expected_on_hand');
            $table->unsignedInteger('expected_reserved');
            $table->unsignedInteger('expected_pending_inspection');
            $table->unsignedInteger('expected_damaged');
            $table->unsignedInteger('counted_on_hand');
            $table->unique(['bulk_inventory_count_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_inventory_count_lines');
        Schema::dropIfExists('bulk_inventory_counts');
    }
};
