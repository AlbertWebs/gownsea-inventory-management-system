<?php

namespace App\Http\Requests;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $lead = $this->route('lead');

        return $lead instanceof Lead && ($this->user()?->can('update', $lead) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('is_active', true)],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
            'contact_name' => ['required', 'string', 'max:180'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'source' => ['required', Rule::in(['referral', 'website', 'social', 'tender', 'direct', 'other'])],
            'stage' => ['required', Rule::in(['new', 'contacted', 'quotation_sent', 'negotiation', 'won', 'lost'])],
            'estimated_value' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'next_action' => ['nullable', 'string', 'max:180'],
            'follow_up_at' => ['nullable', 'date'],
            'lost_reason' => ['nullable', 'required_if:stage,lost', 'string', 'min:5', 'max:180'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
