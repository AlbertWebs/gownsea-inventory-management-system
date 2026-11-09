<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $location = $this->route('location');

        return $location instanceof Location && ($this->user()?->can('update', $location) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^(?:\+254|254|0)?[17][0-9]{8}$/'],
            'timezone' => ['required', 'timezone'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
