<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hire_penalty_accruals', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('hire_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_configuration_id')->constrained('business_configurations')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->date('occurred_on')->index();
            $table->string('recognition_basis', 24);
            $table->timestamps();
            $table->index(['hire_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hire_penalty_accruals');
    }
};
