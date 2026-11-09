<?php

namespace App\Http\Requests;

use App\Models\CustomOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomOrderShipmentEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('customOrder');

        return $order instanceof CustomOrder && ($this->user()?->can('update', $order) ?? false);
    }

    public function rules(): array
    {
        return [
            'event_type' => ['required', Rule::in(['picked_up', 'in_transit', 'attempted_delivery', 'delivered', 'delayed', 'exception'])],
            'provider_reference' => ['nullable', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:2000'],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
