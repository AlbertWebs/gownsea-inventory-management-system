<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryCountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'management', 'storekeeper'], true);
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
            'asset_codes' => ['required', 'array', 'min:1', 'max:500'],
            'asset_codes.*' => ['required', 'string', 'max:64', 'distinct:ignore_case'],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
