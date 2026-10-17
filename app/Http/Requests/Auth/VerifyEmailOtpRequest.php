<?php

namespace App\Http\Requests\Auth;

class VerifyEmailOtpRequest extends RequestEmailOtpRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'otp' => ['bail', 'required', 'string', 'regex:/^\d{' . config('vistaro.otp.length') . '}$/'],
        ];
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
