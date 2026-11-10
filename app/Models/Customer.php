<?php

namespace App\Models;

use App\Support\KenyanPhoneNumber;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['customer_number', 'type', 'name', 'phone', 'email', 'institution_id', 'source', 'notes', 'sms_opt_out', 'sms_consent_at', 'sms_preferences'];

    protected function casts(): array
    {
        return ['sms_opt_out' => 'boolean', 'sms_consent_at' => 'datetime', 'sms_preferences' => 'array'];
    }

    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $phone): ?string => $phone === null || trim($phone) === '' ? null : KenyanPhoneNumber::normalize($phone));
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class)->withPivot('assigned_by')->withTimestamps();
    }

    public function hires(): HasMany
    {
        return $this->hasMany(Hire::class);
    }
}
