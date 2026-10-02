<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class InitiateVnPayPaymentRequest extends StoreCodOrderRequest
{
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            redirect()->route('checkout.show')->withErrors($validator, 'vnpay'),
        );
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'request_key.required' => 'Thiếu khóa chống tạo giao dịch VNPay trùng. Vui lòng tạo lại báo giá.',
            'request_key.uuid' => 'Khóa chống tạo giao dịch VNPay không hợp lệ.',
        ]);
    }
}
