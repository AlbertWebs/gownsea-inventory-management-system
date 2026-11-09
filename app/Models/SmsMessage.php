<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends Model
{
    protected $fillable = ['idempotency_key', 'customer_id', 'hire_id', 'custom_order_id', 'requested_by', 'location_id', 'template_id', 'recipient', 'body', 'provider', 'status', 'provider_message_id', 'provider_code', 'provider_description', 'attempts', 'queued_at', 'sent_at', 'delivered_at'];

    protected function casts(): array
    {
        return ['queued_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class);
    }
}
