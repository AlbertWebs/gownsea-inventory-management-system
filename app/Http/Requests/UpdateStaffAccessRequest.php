<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Security\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStaffAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'management'], true);
    }

    public function rules(): array
    {
        $roles = ['super_admin', 'management', 'finance', 'sales_crm', 'storekeeper', 'production'];

        return [
            'role' => ['required', Rule::in($roles)],
            'is_active' => ['required', 'boolean'],
            'location_ids' => ['present', 'array', 'max:100'],
            'location_ids.*' => ['integer', Rule::exists('locations', 'id')->where('is_active', true)],
            'primary_location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('is_active', true)],
            'permissions' => ['sometimes', 'array', 'max:'.count(PermissionCatalog::LABELS)],
            'permissions.*' => ['string', Rule::in(array_keys(PermissionCatalog::LABELS))],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $target = $this->route('staff');
            if (! $target instanceof User) {
                return;
            }
            $locations = collect($this->input('location_ids', []))->map(fn ($id): int => (int) $id)->unique();
            $role = (string) $this->input('role');
            $requestedPermissions = $this->input('permissions');
            if (is_array($requestedPermissions)) {
                $invalidPermissions = array_diff($requestedPermissions, PermissionCatalog::forRole($role));
                if ($invalidPermissions !== []) {
                    $validator->errors()->add('permissions', 'A staff member cannot be assigned permissions outside their role defaults.');
                }
            }
            if (! in_array($role, ['super_admin', 'management'], true) && $locations->isEmpty()) {
                $validator->errors()->add('location_ids', 'Assign at least one active branch to operational staff.');
            }
            $primary = $this->input('primary_location_id');
            if ($primary !== null && ! $locations->contains((int) $primary)) {
                $validator->errors()->add('primary_location_id', 'The primary location must also be selected.');
            }
        });
    }
}
