<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_lines', function (Blueprint $table): void {
            $table->string('fulfillment_type', 24)->default('custom_order')->after('description');
            $table->foreignId('product_id')->nullable()->after('fulfillment_type')->constrained()->restrictOnDelete();
            $table->index(['quotation_id', 'revision', 'fulfillment_type']);
        });
    }

    public function down(): void
    {
        Schema::table('quotation_lines', function (Blueprint $table): void {
            $table->dropIndex(['quotation_id', 'revision', 'fulfillment_type']);
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('fulfillment_type');
        });
    }
};
