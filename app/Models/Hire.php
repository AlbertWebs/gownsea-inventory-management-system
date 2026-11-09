<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Hire extends Model
{
    use HasFactory;

    protected $fillable = ['reference', 'idempotency_key', 'customer_id', 'institution_id', 'graduation_event_id', 'contract_reference', 'location_id', 'created_by', 'status', 'event_date', 'issued_at', 'due_at', 'completed_at', 'deposit_minor', 'notes'];

    protected function casts(): array
    {
        return ['event_date' => 'date', 'issued_at' => 'datetime', 'due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function graduationEvent(): BelongsTo
    {
        return $this->belongsTo(GraduationEvent::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(HireItem::class);
    }

    public function depositApplications(): HasMany
    {
        return $this->hasMany(HireDepositApplication::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(HireInvoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
