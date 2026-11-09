<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomOrderFitting extends Model
{
    protected $fillable = ['custom_order_id', 'assigned_to', 'scheduled_by', 'status', 'appointment_at', 'measurements', 'outcome', 'completed_at'];

    protected function casts(): array
    {
        return ['appointment_at' => 'datetime', 'measurements' => 'array', 'completed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomOrder::class, 'custom_order_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }
}
