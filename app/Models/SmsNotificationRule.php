<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsNotificationRule extends Model
{
    protected $fillable = ['key', 'name', 'is_enabled', 'intervals'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'intervals' => 'array'];
    }
}
