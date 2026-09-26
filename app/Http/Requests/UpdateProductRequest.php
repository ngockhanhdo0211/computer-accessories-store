<?php

namespace App\Http\Requests;

use App\Enums\ProductVisibility;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        /** @var Product $product */
        $product = $this->route('product');
        $name = $this->normalizeText($this->input('name'));
        $rawSlug = $this->input('slug');
        $altTexts = $this->input('image_alt_texts', []);

        $this->merge([
            'name' => $name,
            'slug' => blank($rawSlug) ? $product->slug : (is_string($rawSlug) ? Str::slug(trim($rawSlug)) : null),
            'sku' => is_string($this->input('sku')) ? Str::upper(trim($this->input('sku'))) : $this->input('sku'),
            'short_description' => $this->normalizeText($this->input('short_description')),
            'description' => is_string($this->input('description')) ? trim($this->input('description')) : $this->input('description'),
            'sale_price_vnd' => blank($this->input('sale_price_vnd')) ? null : $this->input('sale_price_vnd'),
            'image_alt_texts' => is_array($altTexts)
                ? array_map(fn ($value) => is_string($value) ? trim($value) : $value, $altTexts)
                : $altTexts,
        ]);
    }

    public function rules(): array
    {
        /** @var Product $product */
        $product = $this->route('product');

        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($product)],
            'sku' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', Rule::unique('products', 'sku')->ignore($product)],
            'short_description' => ['required', 'string', 'max:1000'],
            'description' => ['required', 'string', 'max:20000'],
            'price_vnd' => ['required', 'integer', 'min:1'],
            'sale_price_vnd' => ['nullable', 'integer', 'min:1', 'lt:price_vnd'],
            'visibility' => ['required', Rule::enum(ProductVisibility::class)],
            'images' => ['sometimes', 'array', 'max:8'],
            'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image_alt_texts' => ['sometimes', 'array'],
            'image_alt_texts.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return (new StoreProductRequest)->messages();
    }

    private function normalizeText(mixed $value): mixed
    {
        return is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value)) : $value;
    }
}
