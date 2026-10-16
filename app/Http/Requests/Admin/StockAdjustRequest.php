<?php

namespace App\Http\Requests\Admin;

use App\Support\SizeCode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Manual shared-stock adjustment, e.g. { "size": "M", "delta": -2, "note": "2 sold in the shop" }.
 * `location` is optional and only records where the change came from (default: offline = manual shop adjustment);
 * it never selects a different stock. A reason (`note`) is mandatory so every correction stays auditable.
 */
class StockAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('size'))) {
            $this->merge(['size' => SizeCode::normalize($this->input('size'))]);
        }
    }

    public function rules(): array
    {
        return [
            'location' => ['sometimes', 'in:online,offline'],
            'size' => ['required', ...SizeCode::rules()],
            'delta' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
