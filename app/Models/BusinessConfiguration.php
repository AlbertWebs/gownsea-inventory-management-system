<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessConfiguration extends Model
{
    protected $fillable = ['configuration_key', 'configuration', 'effective_from', 'review_status', 'created_by', 'approved_by', 'approved_at', 'approval_reference'];

    protected function casts(): array
    {
        return [
            'configuration' => 'encrypted:array',
            'effective_from' => 'date',
            'approved_at' => 'datetime',
        ];
    }
}
