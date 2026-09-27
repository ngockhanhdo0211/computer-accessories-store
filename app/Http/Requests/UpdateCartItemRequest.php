<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateCartItemRequest extends FormRequest
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

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            redirect()->route('cart.index')
                ->withErrors($validator)
                ->withInput([
                    'quantity' => $this->input('quantity'),
                    'cart_item_id' => $this->route('cartItem')->id,
                ]),
        );
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
