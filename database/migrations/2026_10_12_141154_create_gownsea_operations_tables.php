<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->default('storekeeper')->index();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true)->index();
        });

        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('type', 24)->index();
            $table->string('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('timezone', 64)->default('Africa/Nairobi');
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('location_user', function (Blueprint $table): void {
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->primary(['location_id', 'user_id']);
        });

        Schema::create('academic_classifications', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('label');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('institutions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->index();
            $table->string('type', 32)->index();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address')->nullable();
            $table->string('county', 64)->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('customer_number', 32)->unique();
            $table->string('type', 24)->default('individual')->index();
            $table->string('name')->index();
            $table->string('phone', 20)->nullable()->index();
            $table->string('email')->nullable();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 32)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('name')->index();
            $table->string('category', 32)->index();
            $table->string('tracking_type', 16)->default('serialized')->index();
            $table->foreignId('academic_classification_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('qualification_level', 32)->nullable()->index();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('size', 24)->nullable()->index();
            $table->string('color', 48)->nullable();
            $table->string('faculty')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('purchase_cost_minor')->default(0);
            $table->unsignedBigInteger('sale_price_minor')->default(0);
            $table->unsignedBigInteger('hire_rate_minor')->default(0);
            $table->unsignedInteger('reorder_threshold')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('idempotency_key', 96)->nullable()->unique();
            $table->foreignId('source_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('destination_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('draft')->index();
            $table->string('carrier')->nullable();
            $table->string('waybill')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['source_location_id', 'status']);
            $table->index(['destination_location_id', 'status']);
        });

        Schema::create('physical_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('asset_code', 64)->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('home_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('current_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('custodian_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('custodian_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('custody_type', 24)->default('location')->index();
            $table->string('status', 24)->default('available')->index();
            $table->string('condition', 24)->default('good')->index();
            $table->foreignId('current_transfer_id')->nullable()->constrained('stock_transfers')->nullOnDelete();
            $table->timestamp('last_verified_at')->nullable()->index();
            $table->timestamps();
            $table->index(['current_location_id', 'status']);
            $table->index(['product_id', 'status']);
        });

        Schema::create('stock_transfer_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('physical_asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('receipt_condition', 24)->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['stock_transfer_id', 'physical_asset_id']);
            $table->index(['physical_asset_id', 'received_at']);
        });

        Schema::create('asset_custody_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('physical_asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('stock_transfer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 32)->index();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->string('from_custody_type', 24)->nullable();
            $table->string('to_custody_type', 24)->nullable();
            $table->string('idempotency_key', 96)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['physical_asset_id', 'occurred_at']);
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('physical_asset_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('stock_transfer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('movement_type', 32)->index();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('idempotency_key', 96)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['from_location_id', 'occurred_at']);
            $table->index(['to_location_id', 'occurred_at']);
        });

        Schema::create('hires', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('idempotency_key', 96)->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 28)->default('reserved')->index();
            $table->date('event_date')->nullable()->index();
            $table->timestamp('issued_at')->nullable()->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('deposit_minor')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'status']);
        });

        Schema::create('hire_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hire_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('physical_asset_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('product_name_snapshot');
            $table->string('classification_code_snapshot', 32)->nullable();
            $table->string('qualification_snapshot', 32)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->unsignedBigInteger('unit_rate_minor');
            $table->unsignedBigInteger('penalty_assessed_minor')->default(0);
            $table->timestamp('returned_at')->nullable();
            $table->string('return_condition', 24)->nullable();
            $table->string('status', 24)->default('reserved')->index();
            $table->timestamps();
            $table->unique('physical_asset_id');
            $table->index(['hire_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 48)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('hire_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('KES');
            $table->string('method', 24)->index();
            $table->string('purpose', 24)->default('service_charge')->index();
            $table->string('status', 24)->default('received')->index();
            $table->string('provider_reference')->nullable()->unique();
            $table->timestamp('received_at')->index();
            $table->timestamps();
            $table->index(['customer_id', 'received_at']);
        });

        Schema::create('graduation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->date('event_date')->index();
            $table->string('venue')->nullable();
            $table->unsignedInteger('expected_quantity')->default(0);
            $table->date('collection_date')->nullable();
            $table->date('delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('planned')->index();
            $table->timestamps();
            $table->index(['event_date', 'status']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64)->index();
            $table->string('subject_type', 64)->nullable()->index();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('graduation_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('hire_items');
        Schema::dropIfExists('hires');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('asset_custody_events');
        Schema::dropIfExists('stock_transfer_assets');
        Schema::dropIfExists('physical_assets');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('products');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('institutions');
        Schema::dropIfExists('academic_classifications');
        Schema::dropIfExists('location_user');
        Schema::dropIfExists('locations');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['role', 'phone', 'is_active']);
        });
    }
};
