<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 48)->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->string('request_hash', 64);
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sold_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('total_minor');
            $table->string('status', 24)->default('completed')->index();
            $table->timestamp('sold_at')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'sold_at']);
        });

        Schema::create('bulk_sale_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bulk_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot', 64);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();
            $table->unique(['bulk_sale_id', 'product_id']);
        });

        Schema::create('bulk_sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 48)->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->string('request_hash', 64);
            $table->foreignId('bulk_sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_refund_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('total_minor');
            $table->text('reason');
            $table->timestamp('returned_at')->index();
            $table->timestamps();
        });

        Schema::create('bulk_sale_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bulk_sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->string('condition', 24);
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('bulk_sale_id')->nullable()->after('custom_order_id')->constrained('bulk_sales')->restrictOnDelete();
            $table->index(['bulk_sale_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_sale_return_lines');
        Schema::dropIfExists('bulk_sale_returns');
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['bulk_sale_id', 'status']);
            $table->dropConstrainedForeignId('bulk_sale_id');
        });
        Schema::dropIfExists('bulk_sale_lines');
        Schema::dropIfExists('bulk_sales');
    }
};
