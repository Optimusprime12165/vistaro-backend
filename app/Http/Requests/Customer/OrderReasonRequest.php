<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/** Reason is optional for cancelling, required for a return. Status itself is never accepted from the client. */
class OrderReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->routeIs('v1.customer.orders.return');

        return ['reason' => [$required ? 'required' : 'nullable', 'string', 'min:5', 'max:255']];
    }

    public function reason(): ?string
    {
        $r = $this->validated('reason');

        return $r !== null ? trim($r) : null;
    }
}
