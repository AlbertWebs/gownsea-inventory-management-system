<?php

namespace App\Http\Requests;

use App\Models\GraduationEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGraduationEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof GraduationEvent && ($this->user()?->can('update', $event) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'institution_id' => ['required', 'integer', Rule::exists('institutions', 'id')->where('is_active', true)],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('is_active', true)],
            'name' => ['required', 'string', 'max:180'],
            'event_date' => ['required', 'date'],
            'venue' => ['nullable', 'string', 'max:180'],
            'expected_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'collection_date' => ['nullable', 'date'],
            'delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'status' => ['required', Rule::in(['planned', 'confirmed', 'completed', 'cancelled'])],
        ];
    }
}
