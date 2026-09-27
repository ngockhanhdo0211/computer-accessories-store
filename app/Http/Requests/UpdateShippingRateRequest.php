<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShippingRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('fee_vnd'))) {
            $this->merge(['fee_vnd' => trim($this->input('fee_vnd'))]);
        }
    }

    public function rules(): array
    {
        return [
            'fee_vnd' => ['bail', 'required', 'regex:/^\d+$/', 'integer', 'min:0', 'max:9223372036854775807'],
        ];
    }

    public function messages(): array
    {
        return [
            'fee_vnd.required' => 'Vui lòng nhập phí vận chuyển.',
            'fee_vnd.regex' => 'Phí vận chuyển phải là số nguyên VND không âm.',
            'fee_vnd.integer' => 'Phí vận chuyển phải là số nguyên VND.',
            'fee_vnd.min' => 'Phí vận chuyển không được âm.',
            'fee_vnd.max' => 'Phí vận chuyển vượt phạm vi lưu trữ cho phép.',
        ];
    }
}
