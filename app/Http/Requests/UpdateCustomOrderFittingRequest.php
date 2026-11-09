<?php

namespace App\Http\Requests;

use App\Models\CustomOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomOrderFittingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('customOrder');

        return $order instanceof CustomOrder && ($this->user()?->can('update', $order) ?? false);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['completed', 'cancelled', 'no_show'])],
            'measurements' => ['nullable', 'array', 'max:100'],
            'measurements.*' => ['string', 'max:80'],
            'outcome' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
