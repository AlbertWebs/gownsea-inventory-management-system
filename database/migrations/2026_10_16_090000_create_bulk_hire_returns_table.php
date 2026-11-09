<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_stock_balances', function (Blueprint $table): void {
            $table->unsignedInteger('pending_inspection')->default(0);
            $table->unsignedInteger('damaged')->default(0);
        });

        Schema::create('bulk_hire_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hire_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('inspected_quantity')->default(0);
            $table->unsignedBigInteger('penalty_assessed_minor')->default(0);
            $table->string('condition', 24);
            $table->string('idempotency_key', 100)->unique();
            $table->text('notes')->nullable();
            $table->timestamp('returned_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_hire_returns');
        Schema::table('bulk_stock_balances', function (Blueprint $table): void {
            $table->dropColumn(['pending_inspection', 'damaged']);
        });
    }
};
