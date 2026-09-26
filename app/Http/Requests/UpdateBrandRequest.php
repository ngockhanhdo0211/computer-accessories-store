<?php

namespace App\Http\Requests;

use App\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        /** @var Brand $brand */
        $brand = $this->route('brand');
        $normalized = [];

        if (is_string($this->input('name'))) {
            $normalized['name'] = trim(preg_replace('/\s+/u', ' ', $this->input('name')));
        }

        if (blank($this->input('slug'))) {
            $normalized['slug'] = $brand->slug;
        } elseif (is_string($this->input('slug'))) {
            $normalized['slug'] = Str::slug(trim($this->input('slug')));
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        /** @var Brand $brand */
        $brand = $this->route('brand');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('brands', 'slug')->ignore($brand)],
            'is_visible' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên thương hiệu.',
            'name.string' => 'Tên thương hiệu không hợp lệ.',
            'name.max' => 'Tên thương hiệu tối đa 255 ký tự.',
            'slug.required' => 'Slug không được để trống.',
            'slug.string' => 'Slug không hợp lệ.',
            'slug.max' => 'Slug tối đa 255 ký tự.',
            'slug.regex' => 'Slug chỉ gồm chữ thường không dấu, số và dấu gạch ngang.',
            'slug.unique' => 'Slug này đã được sử dụng.',
            'is_visible.required' => 'Vui lòng chọn trạng thái.',
            'is_visible.boolean' => 'Trạng thái không hợp lệ.',
        ];
    }
}
