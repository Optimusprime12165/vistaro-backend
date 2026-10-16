<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Create (POST) / update (PATCH) a Staff account. There is deliberately NO `role` field: staff can only ever be created as Staff
 * and no endpoint can turn anybody into a Super Admin. The password is write-only and stored hashed.
 */
class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $req = $create ? 'required' : 'sometimes';
        $id = $this->route('admin')?->id;

        return [
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'email' => [$req, 'email:rfc', 'max:190', Rule::unique('admins', 'email')->ignore($id)],
            'password' => $create ? ['required', 'string', Password::min((int) config('vistaro.auth.admin_password_min'))->mixedCase()->numbers()->symbols()] : ['prohibited'],
            'role' => ['prohibited'],
            'is_active' => ['prohibited'],        // use /activate and /deactivate
        ];
    }
}
