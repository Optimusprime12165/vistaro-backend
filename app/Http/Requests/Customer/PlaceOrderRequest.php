<?php

namespace App\Http\Requests\Customer;

use App\Enums\PaymentMethod;
use App\Models\Address;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /orders. The Idempotency-Key HEADER is mandatory; totals/prices/items are never accepted from the client. */
class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,100}$/'],
            'address_id' => ['required', 'integer', Rule::exists('addresses', 'id')->where('user_id', $this->user()->id)],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required.',
            'idempotency_key.regex' => 'The Idempotency-Key header must be 8-100 characters: letters, digits, . _ : -',
        ];
    }

    public function address(): Address
    {
        return $this->user()->addresses()->findOrFail((int) $this->validated('address_id'));
    }

    public function method(): PaymentMethod
    {
        return PaymentMethod::from($this->validated('payment_method'));
    }

    public function key(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
