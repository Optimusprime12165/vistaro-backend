<?php

namespace App\Http\Requests\Catalog;

use App\Support\SizeCode;
use App\Http\Requests\ListRequest;

/** Public product listing: all filters are validated and whitelisted; nothing reaches SQL unchecked. */
class ProductIndexRequest extends ListRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function sortFields(): array
    {
        return ['featured', 'newest', 'price_asc', 'price_desc', 'best'];
    }

    protected function prepareForValidation(): void
    {
        // ?size=M and ?size[]=M&size[]=L are both accepted
        foreach (['size', 'color', 'fit', 'fabric', 'pattern'] as $key) {
            if ($this->has($key) && ! is_array($this->input($key))) {
                $this->merge([$key => [$this->input($key)]]);
            }
        }
    }

    protected function filterRules(): array
    {
        $list = ['sometimes', 'array', 'max:10'];
        $item = ['string', 'max:60'];

        return [
            'category' => ['sometimes', 'string', 'max:140'],
            'sub_category' => ['sometimes', 'string', 'max:140'],
            'size' => $list,
            'size.*' => ['string', 'max:16', 'regex:' . SizeCode::PATTERN],
            'price_min' => ['sometimes', 'numeric', 'min:0', 'max:10000000'],
            'price_max' => ['sometimes', 'numeric', 'min:0', 'max:10000000', 'gte:price_min'],
            'color' => $list, 'color.*' => $item,
            'fit' => $list, 'fit.*' => $item,
            'fabric' => $list, 'fabric.*' => $item,
            'pattern' => $list, 'pattern.*' => $item,
            'badge' => ['sometimes', 'string', function (string $a, mixed $v, \Closure $fail) {
                if ($v === 'trending') {
                    $fail('The "trending" badge is not available: it required product ratings, which are not collected.');
                } elseif (! in_array($v, ['new', 'best', 'sale'], true)) {
                    $fail('The badge must be one of: new, best, sale.');
                }
            }],
        ];
    }
}
