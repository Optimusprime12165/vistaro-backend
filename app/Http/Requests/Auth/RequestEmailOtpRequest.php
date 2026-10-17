<?php

namespace App\Http\Requests\Auth;

use App\Support\EmailAddress;
use Illuminate\Foundation\Http\FormRequest;

class RequestEmailOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => EmailAddress::normalize($this->input('email'))]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['bail', 'required', 'string', 'max:191', 'email:rfc']];
    }

    /** Lower-cased email; only call after validation. */
    public function email(): string
    {
        return (string) $this->validated('email');
    }
}
