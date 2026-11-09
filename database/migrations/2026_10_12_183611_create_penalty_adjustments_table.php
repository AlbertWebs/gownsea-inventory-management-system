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
        Schema::create('penalty_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('hire_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('kind', 24)->default('waiver');
            $table->text('reason');
            $table->timestamp('adjusted_at')->index();
            $table->timestamps();
            $table->index(['hire_id', 'kind', 'adjusted_at']);
            $table->index(['location_id', 'adjusted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penalty_adjustments');
    }
};
