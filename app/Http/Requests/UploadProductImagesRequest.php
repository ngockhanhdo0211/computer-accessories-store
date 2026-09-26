<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadProductImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $altTexts = $this->input('image_alt_texts', []);

        $this->merge([
            'image_alt_texts' => is_array($altTexts)
                ? array_map(fn ($value) => is_string($value) ? trim($value) : $value, $altTexts)
                : $altTexts,
        ]);
    }

    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:8'],
            'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image_alt_texts' => ['sometimes', 'array'],
            'image_alt_texts.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'images.required' => 'Vui lòng chọn ít nhất một ảnh.',
            'images.max' => 'Mỗi sản phẩm có tối đa 8 ảnh.',
            'images.*.image' => 'Tệp tải lên phải là ảnh hợp lệ.',
            'images.*.mimes' => 'Ảnh chỉ chấp nhận JPG, JPEG, PNG hoặc WebP.',
            'images.*.max' => 'Mỗi ảnh tối đa 5 MB.',
            'image_alt_texts.*.max' => 'Mô tả ảnh tối đa 255 ký tự.',
        ];
    }
}
