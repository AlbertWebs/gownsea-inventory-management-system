<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraduationEvent extends Model
{
    protected $fillable = ['institution_id', 'location_id', 'owner_id', 'name', 'event_date', 'venue', 'expected_quantity', 'collection_date', 'delivery_date', 'notes', 'status'];

    protected function casts(): array
    {
        return ['event_date' => 'date', 'collection_date' => 'date', 'delivery_date' => 'date'];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
