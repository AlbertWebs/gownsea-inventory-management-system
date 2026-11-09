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
        Schema::create('graduation_event_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('graduation_event_id')->constrained()->cascadeOnDelete();
            $table->date('event_date');
            $table->unsignedTinyInteger('days_before');
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['graduation_event_id', 'event_date', 'days_before'], 'graduation_event_reminder_once');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('graduation_event_reminders');
    }
};
