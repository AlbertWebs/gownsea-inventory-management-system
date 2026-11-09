<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sms_templates')) {
            return;
        }

        $now = now();
        DB::table('sms_templates')->insertOrIgnore([
            ['key' => 'custom_order_confirmation', 'name' => 'Custom order confirmation', 'body' => 'GOWNSEA: Order {reference} for {item} is confirmed. Expected completion: {expected_date}.', 'placeholders' => json_encode(['reference', 'item', 'expected_date']), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'custom_order_ready', 'name' => 'Custom order ready', 'body' => 'GOWNSEA: Order {reference} is ready for collection. Please contact your branch to arrange pickup.', 'placeholders' => json_encode(['reference']), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'custom_order_dispatched', 'name' => 'Custom order dispatched', 'body' => 'GOWNSEA: Order {reference} has been dispatched. Tracking: {tracking}.', 'placeholders' => json_encode(['reference', 'tracking']), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'custom_order_delivered', 'name' => 'Custom order delivered', 'body' => 'GOWNSEA: Order {reference} has been marked delivered. Thank you for choosing us.', 'placeholders' => json_encode(['reference']), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('sms_templates')) {
            DB::table('sms_templates')->whereIn('key', ['custom_order_confirmation', 'custom_order_ready', 'custom_order_dispatched', 'custom_order_delivered'])->delete();
        }
    }
};
