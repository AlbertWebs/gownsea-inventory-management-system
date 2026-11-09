<?php

namespace App\Models;

use App\Support\KenyanPhoneNumber;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Institution extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'email', 'phone', 'address', 'county', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $phone): ?string => $phone === null || trim($phone) === '' ? null : KenyanPhoneNumber::normalize($phone));
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(GraduationEvent::class);
    }
}
