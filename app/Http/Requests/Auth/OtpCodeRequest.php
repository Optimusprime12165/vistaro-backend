<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** Just the code: used by the signed-in "verify my email" step (the email is the account's own). */
class OtpCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['otp' => ['bail', 'required', 'string', 'regex:/^\d{' . config('vistaro.otp.length') . '}$/']];
    }

    public function messages(): array
    {
        return ['otp.regex' => 'The OTP must be ' . config('vistaro.otp.length') . ' digits.'];
    }

    public function otp(): string
    {
        return (string) $this->validated('otp');
    }
}
