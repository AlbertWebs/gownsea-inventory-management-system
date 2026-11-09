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
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 48)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_name', 180);
            $table->string('phone', 32)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('source', 24)->default('direct')->index();
            $table->string('stage', 24)->default('new')->index();
            $table->unsignedBigInteger('estimated_value_minor')->default(0);
            $table->unsignedTinyInteger('probability')->nullable();
            $table->string('next_action', 180)->nullable();
            $table->timestamp('follow_up_at')->nullable()->index();
            $table->string('lost_reason', 180)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'stage', 'follow_up_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
