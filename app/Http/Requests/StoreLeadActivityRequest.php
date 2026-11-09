<?php

namespace App\Http\Requests;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lead = $this->route('lead');

        return $lead instanceof Lead && ($this->user()?->can('update', $lead) ?? false);
    }

    public function rules(): array
    {
        return [
            'activity_type' => ['required', Rule::in(['call', 'email', 'meeting', 'note'])],
            'details' => ['required', 'string', 'min:2', 'max:5000'],
            'happened_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }
}
