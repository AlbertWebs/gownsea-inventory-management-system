<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EtimsSubmission extends Model
{
    protected $fillable = [
        'document_type', 'document_id', 'credit_note_id', 'provider', 'status', 'attempts', 'request_payload',
        'response_payload', 'result_code', 'result_message', 'remote_invoice_number', 'remote_receipt_number',
        'internal_data', 'receipt_signature', 'sdc_id', 'mrc_number', 'submitted_at', 'last_attempt_at', 'last_error', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'encrypted:array', 'response_payload' => 'encrypted:array',
            'submitted_at' => 'datetime', 'last_attempt_at' => 'datetime', 'attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (EtimsSubmission $submission): void {
            if ($submission->getOriginal('status') === 'submitted' && $submission->isDirty()) {
                throw new LogicException('Accepted eTIMS submission details are immutable.');
            }
        });

        static::deleting(fn () => throw new LogicException('eTIMS submission records cannot be deleted.'));
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }
}
