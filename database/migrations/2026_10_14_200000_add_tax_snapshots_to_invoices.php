<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hire_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->foreignId('tax_configuration_id')->nullable()->after('tax_minor')->constrained('business_configurations')->nullOnDelete();
        });
        Schema::table('hire_invoice_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('net_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->string('tax_code', 48)->nullable();
            $table->string('tax_treatment', 24)->nullable();
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->string('tax_account_code', 16)->nullable();
            $table->string('revenue_account_code', 16)->nullable();
        });
        Schema::table('custom_order_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->foreignId('tax_configuration_id')->nullable()->after('tax_minor')->constrained('business_configurations')->nullOnDelete();
        });
        Schema::table('custom_order_invoice_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('net_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->string('tax_code', 48)->nullable();
            $table->string('tax_treatment', 24)->nullable();
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->string('tax_account_code', 16)->nullable();
            $table->string('revenue_account_code', 16)->nullable();
        });

        DB::table('hire_invoices')->update(['subtotal_minor' => DB::raw('total_minor')]);
        DB::table('hire_invoice_lines')->update(['net_minor' => DB::raw('line_total_minor')]);
        DB::table('custom_order_invoices')->update(['subtotal_minor' => DB::raw('total_minor')]);
        DB::table('custom_order_invoice_lines')->update(['net_minor' => DB::raw('line_total_minor')]);
    }

    public function down(): void
    {
        Schema::table('custom_order_invoice_lines', fn (Blueprint $table) => $table->dropColumn(['net_minor', 'tax_minor', 'tax_code', 'tax_treatment', 'tax_rate', 'tax_account_code', 'revenue_account_code']));
        Schema::table('custom_order_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tax_configuration_id');
            $table->dropColumn(['subtotal_minor', 'tax_minor']);
        });
        Schema::table('hire_invoice_lines', fn (Blueprint $table) => $table->dropColumn(['net_minor', 'tax_minor', 'tax_code', 'tax_treatment', 'tax_rate', 'tax_account_code', 'revenue_account_code']));
        Schema::table('hire_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tax_configuration_id');
            $table->dropColumn(['subtotal_minor', 'tax_minor']);
        });
    }
};
