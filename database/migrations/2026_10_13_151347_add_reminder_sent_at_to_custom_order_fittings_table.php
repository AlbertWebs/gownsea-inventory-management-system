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
        Schema::table('custom_order_fittings', function (Blueprint $table): void {
            $table->timestamp('reminder_sent_at')->nullable()->after('appointment_at');
            $table->index(['status', 'reminder_sent_at', 'appointment_at'], 'fittings_reminder_queue_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_order_fittings', function (Blueprint $table): void {
            $table->dropIndex('fittings_reminder_queue_idx');
            $table->dropColumn('reminder_sent_at');
        });
    }
};
