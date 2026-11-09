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
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('custom_order_id')->nullable()->after('hire_id')->constrained()->nullOnDelete();
            $table->index(['custom_order_id', 'purpose', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['custom_order_id', 'purpose', 'received_at']);
            $table->dropConstrainedForeignId('custom_order_id');
        });
    }
};
