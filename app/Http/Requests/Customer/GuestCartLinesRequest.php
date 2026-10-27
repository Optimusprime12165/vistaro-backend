<?php

namespace App\Http\Requests\Customer;

use App\Support\SizeCode;
use Illuminate\Foundation\Http\FormRequest;

/** Body: { lines: [{ product_id, size, color_id?, quantity }] }. Prices/totals are never accepted. */
class GuestCartLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');
        if (is_array($lines)) {
            $this->merge(['lines' => array_map(
                fn ($l) => is_array($l) && is_string($l['size'] ?? null) ? array_merge($l, ['size' => SizeCode::normalize($l['size'])]) : $l,
                $lines,
            )]);
        }
    }

    public function rules(): array
    {
        $max = (int) config('vistaro.cart.max_line_quantity');

        return [
            'lines' => ['present', 'array', 'max:50'],
            'lines.*.product_id' => ['required', 'integer', 'min:1'],
            'lines.*.size' => ['required', ...SizeCode::rules()],
            'lines.*.color_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', "max:{$max}"],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function lines(): array
    {
        return array_values($this->validated()['lines'] ?? []);
    }
}
