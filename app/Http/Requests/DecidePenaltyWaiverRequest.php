<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecidePenaltyWaiverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'management', 'finance'], true)
            && ($this->user()?->hasPermission('hiring.penalty.manage') ?? false);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'decision_reason' => ['required_if:status,rejected', 'nullable', 'string', 'min:5', 'max:1000'],
        ];
    }
}
