<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderProductImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'ordered_image_ids' => ['required', 'array', 'min:1', 'max:8'],
            'ordered_image_ids.*' => ['required', 'integer', 'distinct'],
            'primary_image_id' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'ordered_image_ids.required' => 'Vui lòng gửi thứ tự ảnh.',
            'ordered_image_ids.*.distinct' => 'Danh sách ảnh không được trùng.',
            'primary_image_id.required' => 'Vui lòng chọn ảnh đại diện.',
        ];
    }
}
