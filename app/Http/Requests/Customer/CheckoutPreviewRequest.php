<?php

namespace App\Http\Requests\Customer;

use App\Enums\PaymentMethod;
use App\Models\Address;
use App\Services\Commerce\AddressService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // scoped to the signed-in customer: someone else's address id fails validation exactly like a missing one
            'address_id' => ['nullable', 'integer', Rule::exists('addresses', 'id')->where('user_id', $this->user()->id)],
            'payment_method' => ['sometimes', Rule::enum(PaymentMethod::class)],
        ];
    }

    /** The selected address, or the customer's default when `address_id` is omitted. */
    public function address(): Address
    {
        $id = $this->validated('address_id');

        return app(AddressService::class)->resolveForCheckout($this->user(), $id === null ? null : (int) $id);
    }

    public function method(): PaymentMethod
    {
        return PaymentMethod::from($this->validated('payment_method', 'cod'));
    }
}
