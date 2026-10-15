<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** { "status": "packed", "reason": "...", "restock": true (only for "returned") } */
class TransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(OrderStatus::class), 'not_in:pickup_requested'],
            'reason' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => in_array($this->input('status'), ['cancelled', 'delivery_failed'], true)), 'min:3'],
            'restock' => ['nullable', 'boolean', Rule::requiredIf(fn () => $this->input('status') === 'returned')],
        ];
    }

    public function messages(): array
    {
        return [
            'status.not_in' => 'Use the pickup endpoint (POST /admin/orders/{id}/pickup) to book the shipment.',
            'restock.required' => 'Say whether the returned items go back on sale (restock: true/false).',
        ];
    }

    public function target(): OrderStatus
    {
        return OrderStatus::from($this->validated('status'));
    }
}
