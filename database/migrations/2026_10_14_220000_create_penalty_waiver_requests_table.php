<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penalty_waiver_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('hire_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->text('reason');
            $table->string('status', 24)->default('pending')->index();
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penalty_waiver_requests');
    }
};
