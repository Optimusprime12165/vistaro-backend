<?php

namespace App\Http\Requests\Admin;

use App\Support\SizeCode;
use Illuminate\Foundation\Http\FormRequest;

/** { "sizes": { "M": 12, "L": 0 }, "note": "stock take" } : absolute SHARED quantities per size. `location` only tags the audit entry. */
class StockSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location' => ['sometimes', 'in:online,offline'],
            'sizes' => ['required', 'array', 'min:1', 'max:30', function (string $a, mixed $v, \Closure $fail) {
                foreach ((array) $v as $size => $qty) {
                    $code = SizeCode::normalize((string) $size);
                    if ($code === '' || ! preg_match(SizeCode::PATTERN, $code)) {
                        $fail("\"{$size}\" is not a valid size code.");
                    } elseif (! is_int($qty) && ! (is_string($qty) && ctype_digit($qty)) || (int) $qty < 0 || (int) $qty > 1_000_000) {
                        $fail("The quantity for {$size} must be a whole number between 0 and 1,000,000.");
                    }
                }
            }],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
