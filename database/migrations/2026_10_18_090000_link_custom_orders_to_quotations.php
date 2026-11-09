<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_orders', function (Blueprint $table): void {
            $table->foreignId('origin_quotation_id')->nullable()->unique()->after('owner_id')->constrained('quotations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('custom_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('origin_quotation_id');
        });
    }
};
