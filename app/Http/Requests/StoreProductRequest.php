<?php

namespace App\Http\Requests;

use App\Enums\ProductVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->normalizeText($this->input('name'));
        $rawSlug = $this->input('slug');
        $altTexts = $this->input('image_alt_texts', []);

        $this->merge([
            'name' => $name,
            'slug' => blank($rawSlug)
                ? (is_string($name) ? Str::slug($name) : null)
                : (is_string($rawSlug) ? Str::slug(trim($rawSlug)) : null),
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
        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')],
            'sku' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', Rule::unique('products', 'sku')],
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
        return [
            'category_id.required' => 'Vui lòng chọn danh mục.',
            'category_id.exists' => 'Danh mục không tồn tại.',
            'brand_id.required' => 'Vui lòng chọn thương hiệu.',
            'brand_id.exists' => 'Thương hiệu không tồn tại.',
            'name.required' => 'Vui lòng nhập tên sản phẩm.',
            'name.max' => 'Tên sản phẩm tối đa 255 ký tự.',
            'slug.required' => 'Không thể tạo slug từ tên này. Hãy nhập slug hợp lệ.',
            'slug.regex' => 'Slug chỉ gồm chữ thường không dấu, số và dấu gạch ngang.',
            'slug.unique' => 'Slug này đã được sử dụng.',
            'sku.required' => 'Vui lòng nhập SKU.',
            'sku.regex' => 'SKU chỉ gồm chữ in hoa, số, dấu chấm, gạch ngang hoặc gạch dưới.',
            'sku.unique' => 'SKU này đã được sử dụng.',
            'short_description.required' => 'Vui lòng nhập mô tả ngắn.',
            'short_description.max' => 'Mô tả ngắn tối đa 1.000 ký tự.',
            'description.required' => 'Vui lòng nhập mô tả sản phẩm.',
            'description.max' => 'Mô tả sản phẩm tối đa 20.000 ký tự.',
            'price_vnd.required' => 'Vui lòng nhập giá sản phẩm.',
            'price_vnd.integer' => 'Giá phải là số nguyên VND, không chứa dấu phân cách.',
            'price_vnd.min' => 'Giá sản phẩm phải lớn hơn 0.',
            'sale_price_vnd.integer' => 'Giá khuyến mãi phải là số nguyên VND.',
            'sale_price_vnd.lt' => 'Giá khuyến mãi phải nhỏ hơn giá gốc.',
            'visibility.required' => 'Vui lòng chọn trạng thái.',
            'visibility.enum' => 'Trạng thái sản phẩm không hợp lệ.',
            'images.max' => 'Mỗi sản phẩm có tối đa 8 ảnh.',
            'images.*.image' => 'Tệp tải lên phải là ảnh hợp lệ.',
            'images.*.mimes' => 'Ảnh chỉ chấp nhận JPG, JPEG, PNG hoặc WebP.',
            'images.*.max' => 'Mỗi ảnh tối đa 5 MB.',
            'image_alt_texts.*.max' => 'Mô tả ảnh tối đa 255 ký tự.',
        ];
    }

    private function normalizeText(mixed $value): mixed
    {
        return is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value)) : $value;
    }
}
