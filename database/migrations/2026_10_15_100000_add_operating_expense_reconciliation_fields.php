<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operating_expenses', function (Blueprint $table): void {
            $table->string('payment_status', 16)->default('unpaid')->index();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_method', 24)->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->date('paid_on')->nullable()->index();
            $table->string('receipt_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('operating_expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn(['payment_status', 'payment_method', 'payment_reference', 'paid_on', 'receipt_path']);
        });
    }
};
