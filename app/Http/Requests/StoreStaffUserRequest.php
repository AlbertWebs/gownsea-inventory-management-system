<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStaffUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'management'], true);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        $roles = $this->user()?->role === 'super_admin'
            ? ['management', 'finance', 'sales_crm', 'storekeeper', 'production', 'super_admin']
            : ['finance', 'sales_crm', 'storekeeper', 'production'];

        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in($roles)],
            'location_ids' => ['present', 'array', 'max:100'],
            'location_ids.*' => ['integer', Rule::exists('locations', 'id')->where('is_active', true)],
            'primary_location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('is_active', true)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $locations = collect($this->input('location_ids', []))->map(fn ($id): int => (int) $id)->unique();
            if (! in_array((string) $this->input('role'), ['super_admin', 'management'], true) && $locations->isEmpty()) {
                $validator->errors()->add('location_ids', 'Assign at least one active branch to operational staff.');
            }

            $primary = $this->input('primary_location_id');
            if ($primary !== null && ! $locations->contains((int) $primary)) {
                $validator->errors()->add('primary_location_id', 'The primary location must also be selected.');
            }
        });
    }
}
