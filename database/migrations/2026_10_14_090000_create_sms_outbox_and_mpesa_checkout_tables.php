<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('sms_opt_out')->default(false)->after('phone');
        });

        Schema::create('sms_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('body');
            $table->json('placeholders');
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sms_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 100)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hire_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('custom_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('sms_templates')->nullOnDelete();
            $table->string('recipient', 24);
            $table->text('body');
            $table->string('provider', 30)->default('onfon');
            $table->string('status', 20)->default('queued')->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('provider_code', 80)->nullable();
            $table->string('provider_description', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('mpesa_stk_pushes', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 48)->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('hire_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('custom_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('purpose', 30);
            $table->string('phone', 16);
            $table->string('status', 20)->default('pending')->index();
            $table->string('merchant_request_id')->nullable();
            $table->string('checkout_request_id')->nullable()->unique();
            $table->string('result_code', 20)->nullable();
            $table->text('result_description')->nullable();
            $table->string('mpesa_receipt', 40)->nullable()->unique();
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->json('callback_payload')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        DB::table('sms_templates')->insert([
            ['key' => 'hire_issued', 'name' => 'Hire issued', 'body' => 'GOWNSEA: Hire {reference} issued. Return by {due_date}. Thank you.', 'placeholders' => json_encode(['reference', 'due_date']), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'hire_returned', 'name' => 'Hire returned', 'body' => 'GOWNSEA: We recorded the return for hire {reference}. Thank you.', 'placeholders' => json_encode(['reference']), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'hire_due', 'name' => 'Hire due reminder', 'body' => 'GOWNSEA reminder: Hire {reference} is due on {due_date}. Please contact your branch if you need assistance.', 'placeholders' => json_encode(['reference', 'due_date']), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'hire_overdue', 'name' => 'Overdue hire reminder', 'body' => 'GOWNSEA: Hire {reference} was due on {due_date}. Please contact your branch about returning the gowns.', 'placeholders' => json_encode(['reference', 'due_date']), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_stk_pushes');
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('sms_templates');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('sms_opt_out'));
    }
};
