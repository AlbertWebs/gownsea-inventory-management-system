<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_counts', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->string('approval_key', 64)->nullable()->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('awaiting_approval')->index();
            $table->unsignedInteger('expected_count')->default(0);
            $table->unsignedInteger('counted_count')->default(0);
            $table->text('notes')->nullable();
            $table->text('approval_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'status', 'created_at']);
        });

        Schema::create('inventory_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('physical_asset_id')->constrained()->restrictOnDelete();
            $table->string('expected_status', 24);
            $table->boolean('was_counted')->default(false);
            $table->timestamp('counted_at')->nullable();
            $table->unique(['inventory_count_id', 'physical_asset_id']);
            $table->index(['inventory_count_id', 'was_counted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_lines');
        Schema::dropIfExists('inventory_counts');
    }
};
