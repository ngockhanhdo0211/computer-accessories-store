<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StockAdjustmentRequest extends FormRequest
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
            'sellable_delta' => ['required', 'integer', 'between:-2147483648,2147483647'],
            'damaged_delta' => ['required', 'integer', 'between:-2147483648,2147483647'],
            'reason' => ['required', 'string', 'max:500', 'not_regex:/^\s*$/u'],
            'request_key' => ['required', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->integer('sellable_delta') === 0 && $this->integer('damaged_delta') === 0) {
                $validator->errors()->add('sellable_delta', 'Ít nhất một chênh lệch phải khác 0.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'sellable_delta.required' => 'Vui lòng nhập chênh lệch tồn bán được.',
            'sellable_delta.integer' => 'Chênh lệch tồn bán được phải là số nguyên.',
            'sellable_delta.between' => 'Chênh lệch tồn bán được vượt phạm vi cho phép.',
            'damaged_delta.required' => 'Vui lòng nhập chênh lệch hàng hỏng.',
            'damaged_delta.integer' => 'Chênh lệch hàng hỏng phải là số nguyên.',
            'damaged_delta.between' => 'Chênh lệch hàng hỏng vượt phạm vi cho phép.',
            'reason.required' => 'Vui lòng nhập lý do.',
            'reason.string' => 'Lý do phải là chuỗi ký tự.',
            'reason.max' => 'Lý do không được vượt quá 500 ký tự.',
            'reason.not_regex' => 'Lý do không được chỉ chứa khoảng trắng.',
            'request_key.required' => 'Thiếu mã chống gửi lặp. Vui lòng tải lại trang.',
            'request_key.uuid' => 'Mã chống gửi lặp không hợp lệ. Vui lòng tải lại trang.',
        ];
    }
}
