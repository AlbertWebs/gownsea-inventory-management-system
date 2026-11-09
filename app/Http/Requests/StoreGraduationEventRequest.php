<?php

namespace App\Http\Requests;

use App\Models\GraduationEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGraduationEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', GraduationEvent::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->eventRules();
    }

    /** @return array<string, array<mixed>|string> */
    protected function eventRules(): array
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
