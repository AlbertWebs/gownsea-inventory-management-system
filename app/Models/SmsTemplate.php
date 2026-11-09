<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsTemplate extends Model
{
    protected $fillable = ['key', 'name', 'body', 'placeholders', 'is_active', 'updated_by'];

    protected function casts(): array
    {
        return ['placeholders' => 'array', 'is_active' => 'boolean'];
    }
}
