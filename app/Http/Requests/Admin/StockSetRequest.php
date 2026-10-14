<?php

namespace App\Http\Requests\Admin;

use App\Enums\Size;
use Illuminate\Foundation\Http\FormRequest;

/** { "location": "online", "sizes": { "M": 12, "L": 0 }, "note": "stock take" } : absolute quantities per size. */
class StockSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location' => ['required', 'in:online,offline'],
            'sizes' => ['required', 'array', 'min:1', 'max:8', function (string $a, mixed $v, \Closure $fail) {
                foreach ((array) $v as $size => $qty) {
                    if (! in_array((string) $size, Size::values(), true)) {
                        $fail("\"{$size}\" is not a supported size.");
                    } elseif (! is_int($qty) && ! (is_string($qty) && ctype_digit($qty)) || (int) $qty < 0 || (int) $qty > 1_000_000) {
                        $fail("The quantity for {$size} must be a whole number between 0 and 1,000,000.");
                    }
                }
            }],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
