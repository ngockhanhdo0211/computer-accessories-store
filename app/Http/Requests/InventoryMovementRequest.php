<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isEmployee() === true || $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason')]);
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'reason' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/u'],
            'request_key' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.required' => 'Vui lòng nhập số lượng.',
            'quantity.integer' => 'Số lượng phải là số nguyên.',
            'quantity.min' => 'Số lượng phải lớn hơn 0.',
            'quantity.max' => 'Số lượng vượt phạm vi cho phép.',
            'reason.required' => 'Vui lòng nhập lý do.',
            'reason.string' => 'Lý do phải là chuỗi ký tự.',
            'reason.max' => 'Lý do không được vượt quá 255 ký tự.',
            'reason.not_regex' => 'Lý do không được chỉ chứa khoảng trắng.',
            'request_key.required' => 'Thiếu mã chống gửi lặp. Vui lòng tải lại trang.',
            'request_key.uuid' => 'Mã chống gửi lặp không hợp lệ. Vui lòng tải lại trang.',
        ];
    }
}
