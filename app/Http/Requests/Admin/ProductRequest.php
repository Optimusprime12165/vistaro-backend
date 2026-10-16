<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProductStatus;
use App\Support\SizeCode;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST) and update (PATCH) a product. Colours/sizes/stock are set on create only; afterwards they have their own endpoints.
 * `channel` (online | offline | both) controls WHERE the product is visible; it never splits stock: each size has ONE shared quantity.
 */
class ProductRequest extends FormRequest
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
        $create = $this->isMethod('POST');
        $req = $create ? 'required' : 'sometimes';
        $id = $this->route('product')?->id;
        $money = ['numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'];

        return [
            'sub_category_id' => [$req, 'integer', Rule::exists('sub_categories', 'id')],
            'name' => [$req, 'string', 'min:2', 'max:160'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($id)],
            'sku' => [$req, 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('products', 'sku')->ignore($id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'price' => [$req, ...$money],
            'mrp' => [$req, ...$money],
            'currency' => ['sometimes', 'in:INR'],
            'attributes' => ['sometimes', 'nullable', 'array', 'max:' . config('vistaro.catalog.max_attributes'), function (string $a, mixed $v, \Closure $fail) {
                foreach ((array) $v as $key => $value) {
                    if (! is_string($key) || ! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/', $key)) {
                        $fail("Attribute name \"{$key}\" must start with a letter and use only letters, digits or underscores (max 32).");
                    } elseif ($value !== null && (! is_scalar($value) || is_bool($value) || mb_strlen((string) $value) > 100)) {
                        $fail("Attribute \"{$key}\" must be a text or number value of at most 100 characters.");
                    }                               // null / empty values are accepted and dropped by the service
                }
            }],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'channel' => ['sometimes', 'in:online,offline,both'],
            'is_available' => ['sometimes', 'boolean'],
            'is_offline_available' => ['sometimes', 'boolean'],
            'is_bestseller' => ['sometimes', 'boolean'],
            'is_new_arrival' => ['sometimes', 'boolean'],

            'colors' => $create ? ['sometimes', 'array', 'max:12'] : ['prohibited'],
            'colors.*.name' => ['required', 'string', 'min:1', 'max:40', 'distinct:ignore_case'],
            'colors.*.hex' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sizes' => $create ? ['sometimes', 'array', 'max:12'] : ['prohibited'],
            'sizes.*.size' => ['required', ...SizeCode::rules(), 'distinct'],
            'sizes.*.quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],      // initial SHARED quantity of this size
            'sizes.*.is_enabled' => ['sometimes', 'boolean'],
            'sizes.*.offline_quantity' => ['prohibited'],                                // stock is shared: there is no separate shop quantity
        ];
    }

    /** MRP must never be below the selling price, also for partial updates (compare against the stored value). */
    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->hasAny(['price', 'mrp'])) {
                return;
            }
            $current = $this->route('product');
            $price = $this->input('price', $current?->price);
            $mrp = $this->input('mrp', $current instanceof Product ? $current->mrp : null);

            if ($price !== null && $mrp !== null && (float) $mrp < (float) $price) {
                $v->errors()->add('mrp', 'The MRP must be greater than or equal to the selling price.');
            }
        }];
    }
}
