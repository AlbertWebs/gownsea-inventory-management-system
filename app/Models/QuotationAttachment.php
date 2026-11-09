<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationAttachment extends Model
{
    protected $fillable = ['quotation_id', 'uploaded_by', 'revision', 'original_name', 'disk', 'path', 'mime_type', 'size_bytes'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'size_bytes' => 'integer'];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
