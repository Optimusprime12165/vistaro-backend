<?php

namespace App\Http\Requests\Customer;

use App\Enums\AddressType;
use App\Support\MobileNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST) / update (PATCH) a delivery address. Phone is stored as E.164; pincode must be a valid 6-digit Indian PIN. */
class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('type'))) {
            $this->merge(['type' => mb_strtolower(trim($this->input('type')))]);     // the UI sends "Home" / "Work" / "Other"
        }
        foreach (['pincode', 'phone'] as $numeric) {                    // JSON numbers are tolerated for these two fields
            if (is_int($this->input($numeric))) {
                $this->merge([$numeric => (string) $this->input($numeric)]);
            }
        }
    }

    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'type' => [$req, Rule::enum(AddressType::class)],
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'phone' => ['bail', $req, 'string', 'max:20', function (string $a, mixed $v, \Closure $fail) {
                if (! is_string($v) || MobileNumber::normalize($v) === null) {
                    $fail('The phone number is not valid.');
                }
            }],
            'line1' => [$req, 'string', 'min:3', 'max:255'],
            'landmark' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => [$req, 'string', 'min:2', 'max:100'],
            'state' => [$req, 'string', 'min:2', 'max:100'],
            'pincode' => [$req, 'string', 'regex:/^[1-9][0-9]{5}$/'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['pincode.regex' => 'The pincode must be a valid 6-digit Indian PIN code.'];
    }

    /** @return array<string,mixed> trimmed, normalised values ready to store */
    public function payload(): array
    {
        $d = $this->validated();
        foreach (['name', 'line1', 'landmark', 'city', 'state'] as $k) {
            if (isset($d[$k])) {
                $d[$k] = trim($d[$k]);
            }
        }
        if (isset($d['phone'])) {
            $d['phone'] = MobileNumber::normalize($d['phone']);
        }

        return $d;
    }
}
