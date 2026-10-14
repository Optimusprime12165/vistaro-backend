<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** multipart `images[]` files and/or `urls[]` (https links, stored as external references, never fetched). */
class ImageUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'images' => ['required_without:urls', 'array', 'max:' . config('vistaro.images.max_per_product')],
            'images.*' => ['file', 'image', 'mimes:' . implode(',', config('vistaro.images.mimes')), 'max:' . config('vistaro.images.max_kb')],
            'urls' => ['required_without:images', 'array', 'max:' . config('vistaro.images.max_per_product')],
            'urls.*' => ['string', 'url:https', 'max:500'],
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
