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
        Schema::create('custom_order_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custom_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('task_type', 32)->default('other')->index();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->string('status', 24)->default('todo')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['custom_order_id', 'status', 'due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_order_tasks');
    }
};
