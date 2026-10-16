<?php

namespace App\Http\Requests\Admin;

use App\Support\SizeCode;
use Illuminate\Foundation\Http\FormRequest;

/** Bulk create/update of the sizes a product is sold in. The (shared) quantity, when given, is set absolutely; is_enabled switches a size on/off for sale. */
class SizesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $sizes = $this->input('sizes');
        if (is_array($sizes)) {
            $this->merge(['sizes' => array_map(fn ($row) => is_array($row) && isset($row['size']) && is_string($row['size'])
                ? ['size' => SizeCode::normalize($row['size'])] + $row : $row, $sizes)]);
        }
    }

    public function rules(): array
    {
        return [
            'sizes' => ['required', 'array', 'min:1', 'max:12'],
            'sizes.*.size' => ['required', ...SizeCode::rules(), 'distinct'],
            'sizes.*.quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'sizes.*.is_enabled' => ['sometimes', 'boolean'],
            'sizes.*.offline_quantity' => ['prohibited'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
