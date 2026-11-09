<?php

namespace App\Http\Requests;

use App\Models\CustomOrder;
use App\Models\CustomOrderTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomOrderTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $order = $this->route('customOrder');
        $task = $this->route('customOrderTask');

        return $order instanceof CustomOrder && $task instanceof CustomOrderTask && $task->custom_order_id === $order->id
            && ($this->user()?->can('update', $order) ?? false);
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
            'status' => ['required', Rule::in(['todo', 'in_progress', 'blocked', 'completed', 'cancelled'])],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
