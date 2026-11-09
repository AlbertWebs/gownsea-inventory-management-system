<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operating_expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('idempotency_key', 36)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('category', 24)->index();
            $table->string('vendor', 160)->nullable();
            $table->string('receipt_reference', 120)->nullable();
            $table->date('incurred_on')->index();
            $table->string('status', 24)->default('pending')->index();
            $table->text('description');
            $table->text('decision_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'status', 'incurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operating_expenses');
    }
};
