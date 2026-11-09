<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    protected $fillable = ['lead_id', 'actor_id', 'activity_type', 'details', 'before_state', 'after_state', 'happened_at'];

    protected function casts(): array
    {
        return ['before_state' => 'array', 'after_state' => 'array', 'happened_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
