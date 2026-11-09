<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaStkPush extends Model
{
    protected $fillable = ['reference', 'idempotency_key', 'customer_id', 'hire_id', 'custom_order_id', 'location_id', 'requested_by', 'amount_minor', 'purpose', 'phone', 'status', 'merchant_request_id', 'checkout_request_id', 'result_code', 'result_description', 'mpesa_receipt', 'payment_id', 'callback_payload', 'requested_at', 'completed_at', 'last_status_check_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'callback_payload' => 'array', 'requested_at' => 'datetime', 'completed_at' => 'datetime', 'last_status_check_at' => 'datetime'];
    }
}
