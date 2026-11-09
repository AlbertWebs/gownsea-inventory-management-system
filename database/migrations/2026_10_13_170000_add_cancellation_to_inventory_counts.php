<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_counts', function (Blueprint $table): void {
            $table->string('cancellation_key', 64)->nullable()->unique();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_counts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropUnique(['cancellation_key']);
            $table->dropColumn(['cancellation_key', 'cancellation_reason', 'cancelled_at']);
        });
    }
};
