<?php

namespace App\Http\Requests\Auth;

use App\Support\MobileNumber;
use Illuminate\Foundation\Http\FormRequest;

class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** A JSON number (9876543210) is accepted as a convenience; arrays/objects fall through to the 'string' rule. */
    protected function prepareForValidation(): void
    {
        if (is_int($this->input('mobile'))) {
            $this->merge(['mobile' => (string) $this->input('mobile')]);
        }
    }

    public function rules(): array
    {
        return [
            'mobile' => ['bail', 'required', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_string($value) || MobileNumber::normalize($value) === null) {
                    $fail('The mobile number is not valid.');
                }
            }],
        ];
    }

    /** Canonical E.164 number; only call after validation. */
    public function mobile(): string
    {
        return MobileNumber::normalize((string) $this->validated('mobile'));
    }
}
