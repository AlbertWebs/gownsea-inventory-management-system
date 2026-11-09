<?php

namespace App\Http\Requests;

use App\Models\CustomOrder;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomOrderFittingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('customOrder');

        return $order instanceof CustomOrder && ($this->user()?->can('update', $order) ?? false);
    }

    public function rules(): array
    {
        return [
            'appointment_at' => ['required', 'date', 'after:now'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
