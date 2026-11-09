<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatingExpense extends Model
{
    protected $fillable = ['reference', 'idempotency_key', 'location_id', 'created_by', 'decided_by', 'paid_by', 'amount_minor', 'category', 'vendor', 'receipt_reference', 'incurred_on', 'status', 'payment_status', 'payment_method', 'payment_reference', 'paid_on', 'receipt_path', 'description', 'decision_reason', 'decided_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'incurred_on' => 'date', 'paid_on' => 'date', 'decided_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
