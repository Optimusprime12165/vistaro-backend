<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Records a refund that was paid OUTSIDE the system (no payment gateway is connected). */
class RefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reference' => ['nullable', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:120']];
    }
}
