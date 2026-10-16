<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** Super Admin sets a new password for a Staff account (account reset). */
class StaffPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', Password::min((int) config('vistaro.auth.admin_password_min'))->mixedCase()->numbers()->symbols()],
        ];
    }
}
