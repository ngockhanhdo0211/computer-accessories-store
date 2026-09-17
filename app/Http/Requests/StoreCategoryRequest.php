<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $name = is_string($this->input('name')) ? trim(preg_replace('/\s+/u', ' ', $this->input('name'))) : $this->input('name');
        $rawSlug = $this->input('slug');
        $slug = is_string($rawSlug) ? Str::slug(trim($rawSlug)) : null;

        $this->merge([
            'name' => $name,
            'slug' => blank($rawSlug) ? (is_string($name) ? Str::slug($name) : null) : $slug,
            'parent_id' => blank($this->input('parent_id')) ? null : $this->input('parent_id'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'is_visible' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên danh mục.',
            'name.string' => 'Tên danh mục không hợp lệ.',
            'name.max' => 'Tên danh mục tối đa 255 ký tự.',
            'slug.required' => 'Không thể tạo slug từ tên này. Hãy nhập slug hợp lệ.',
            'slug.string' => 'Slug không hợp lệ.',
            'slug.max' => 'Slug tối đa 255 ký tự.',
            'slug.regex' => 'Slug chỉ gồm chữ thường không dấu, số và dấu gạch ngang.',
            'slug.unique' => 'Slug này đã được sử dụng.',
            'parent_id.integer' => 'Danh mục cha không hợp lệ.',
            'parent_id.exists' => 'Danh mục cha không tồn tại.',
            'parent_id.not_in' => 'Danh mục không thể làm cha của chính nó.',
            'is_visible.required' => 'Vui lòng chọn trạng thái.',
            'is_visible.boolean' => 'Trạng thái không hợp lệ.',
        ];
    }
}
