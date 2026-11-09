<?php

namespace App\Http\Requests;

use App\Models\CustomOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomOrderTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $order = $this->route('customOrder');

        return $order instanceof CustomOrder && ($this->user()?->can('update', $order) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'task_type' => ['required', Rule::in(['measurement', 'cutting', 'sewing', 'embroidery', 'fitting', 'alteration', 'quality_check', 'packaging', 'other'])],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:3000'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'due_date' => ['nullable', 'date', 'after_or_equal:'.$this->route('customOrder')->order_date->toDateString()],
        ];
    }
}
