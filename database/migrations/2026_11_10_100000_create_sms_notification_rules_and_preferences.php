<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->json('sms_preferences')->nullable());
        Schema::create('sms_notification_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->boolean('is_enabled')->default(true);
            $table->json('intervals')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('sms_notification_rules')->insert([
            ['key' => 'hire_issued', 'name' => 'Hire issued confirmation', 'is_enabled' => true, 'intervals' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'hire_returned', 'name' => 'Gown return confirmation', 'is_enabled' => true, 'intervals' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'hire_due', 'name' => 'Upcoming return reminder', 'is_enabled' => true, 'intervals' => json_encode([3, 1]), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'hire_overdue', 'name' => 'Overdue return reminder', 'is_enabled' => true, 'intervals' => json_encode([1, 3, 7]), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'payment_received', 'name' => 'Payment receipt', 'is_enabled' => true, 'intervals' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'custom_order_confirmation', 'name' => 'Custom order received', 'is_enabled' => true, 'intervals' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'custom_order_ready', 'name' => 'Order ready for collection', 'is_enabled' => true, 'intervals' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'custom_order_dispatched', 'name' => 'Order dispatched', 'is_enabled' => true, 'intervals' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'custom_order_delivered', 'name' => 'Order delivered', 'is_enabled' => true, 'intervals' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'fitting_reminder', 'name' => 'Fitting appointment reminder', 'is_enabled' => true, 'intervals' => json_encode([1]), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'invoice_due', 'name' => 'Upcoming balance reminder', 'is_enabled' => true, 'intervals' => json_encode([3, 1]), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'invoice_overdue', 'name' => 'Overdue balance reminder', 'is_enabled' => true, 'intervals' => json_encode([1, 7, 30]), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'custom_order_cancelled', 'name' => 'Custom order cancellation', 'is_enabled' => true, 'intervals' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'custom_order_shipment_update', 'name' => 'Custom order delivery update', 'is_enabled' => true, 'intervals' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'payment_refunded', 'name' => 'Refund confirmation', 'is_enabled' => true, 'intervals' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('sms_templates')->insertOrIgnore([
            ['key' => 'payment_received', 'name' => 'Payment confirmation', 'body' => 'GOWNSEA: We received KES {amount} for {reference}. Receipt {receipt}. Thank you.', 'placeholders' => json_encode(['amount', 'reference', 'receipt']), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'fitting_reminder', 'name' => 'Fitting reminder', 'body' => 'GOWNSEA reminder: {reference} fitting is scheduled for {appointment}. Please contact your branch if you need to reschedule.', 'placeholders' => json_encode(['reference', 'appointment']), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'invoice_due', 'name' => 'Balance due reminder', 'body' => 'GOWNSEA reminder: KES {balance} is due for {reference} on {due_date}. Please contact your branch if you have already paid.', 'placeholders' => json_encode(['balance', 'reference', 'due_date']), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'invoice_overdue', 'name' => 'Overdue balance reminder', 'body' => 'GOWNSEA: KES {balance} for {reference} was due on {due_date}. Please contact your branch to arrange payment or confirm a recent payment.', 'placeholders' => json_encode(['balance', 'reference', 'due_date']), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'custom_order_cancelled', 'name' => 'Custom order cancelled', 'body' => 'GOWNSEA: Custom order {reference} has been cancelled. Contact your branch if you have questions.', 'placeholders' => json_encode(['reference']), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'custom_order_shipment_update', 'name' => 'Delivery progress update', 'body' => 'GOWNSEA update: Order {reference} is now {status}. Tracking: {tracking}. Contact your branch for help.', 'placeholders' => json_encode(['reference', 'status', 'tracking']), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'payment_refunded', 'name' => 'Refund recorded', 'body' => 'GOWNSEA: A refund of KES {amount} for receipt {receipt} has been recorded. Contact your branch if you need help.', 'placeholders' => json_encode(['amount', 'receipt']), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_notification_rules');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('sms_preferences'));
    }
};
