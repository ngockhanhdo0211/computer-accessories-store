<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && UserRole::tryFrom((string) $user->getRawOriginal('role')) === UserRole::Customer
            && UserStatus::tryFrom((string) $user->getRawOriginal('status')) === UserStatus::Active;
    }

    public function rules(): array
    {
        return [
            'quantity' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_bool($value)) {
                        $fail('Số lượng phải là số nguyên.');
                    }
                },
                'integer',
                'min:1',
                'max:4294967295',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.required' => 'Hãy nhập số lượng.',
            'quantity.integer' => 'Số lượng phải là số nguyên.',
            'quantity.min' => 'Số lượng phải lớn hơn 0.',
            'quantity.max' => 'Số lượng vượt quá giới hạn hỗ trợ.',
        ];
    }
}
