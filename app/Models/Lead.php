<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $fillable = ['reference', 'location_id', 'owner_id', 'customer_id', 'institution_id', 'contact_name', 'phone', 'email', 'source', 'stage', 'estimated_value_minor', 'probability', 'next_action', 'follow_up_at', 'lost_reason', 'notes'];

    protected function casts(): array
    {
        return ['estimated_value_minor' => 'integer', 'probability' => 'integer', 'follow_up_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(LeadTask::class);
    }
}
