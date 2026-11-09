<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Location::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'alpha_dash:ascii', 'unique:locations,code'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['warehouse', 'branch'])],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^(?:\+254|254|0)?[17][0-9]{8}$/'],
            'timezone' => ['required', 'timezone'],
        ];
    }
}
