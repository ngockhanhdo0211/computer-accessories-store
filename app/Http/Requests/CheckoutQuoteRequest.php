<?php

namespace App\Http\Requests;

use App\Support\PhoneNumberNormalizer;
use App\ValueObjects\CheckoutRecipient;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CheckoutQuoteRequest extends FormRequest
{
    private const ALLOWED_FIELDS = [
        'recipient_name',
        'recipient_email',
        'recipient_phone',
        'province',
        'district',
        'ward',
        'address_line',
        'coupon_code',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = $this->only(self::ALLOWED_FIELDS);

        foreach (['recipient_name', 'province', 'district', 'ward', 'address_line'] as $field) {
            if (isset($normalized[$field]) && is_string($normalized[$field])) {
                $collapsed = preg_replace('/\s+/u', ' ', $normalized[$field]);
                $normalized[$field] = $collapsed === null ? $normalized[$field] : trim($collapsed);
            }
        }

        if (isset($normalized['recipient_email']) && is_string($normalized['recipient_email'])) {
            $normalized['recipient_email'] = mb_strtolower(trim($normalized['recipient_email']));
        }

        if (isset($normalized['recipient_phone']) && is_string($normalized['recipient_phone'])) {
            $normalized['recipient_phone'] = PhoneNumberNormalizer::normalize($normalized['recipient_phone']);
        }

        if (isset($normalized['coupon_code']) && is_string($normalized['coupon_code'])) {
            $normalized['coupon_code'] = mb_strtoupper(trim($normalized['coupon_code']));
        }

        $this->replace($normalized);
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            redirect()->route('checkout.show')
                ->withErrors($validator)
                ->withInput($this->only(self::ALLOWED_FIELDS)),
        );
    }

    public function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'recipient_phone' => ['required', 'regex:/^0[35789][0-9]{8}$/'],
            'province' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'ward' => ['required', 'string', 'max:100'],
            'address_line' => ['required', 'string', 'max:500'],
            'coupon_code' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_name.required' => 'Vui lòng nhập tên người nhận.',
            'recipient_name.string' => 'Tên người nhận phải là chuỗi ký tự.',
            'recipient_name.max' => 'Tên người nhận không được vượt quá 255 ký tự.',
            'recipient_email.required' => 'Vui lòng nhập email người nhận.',
            'recipient_email.string' => 'Email người nhận phải là chuỗi ký tự.',
            'recipient_email.email' => 'Email người nhận không hợp lệ.',
            'recipient_email.max' => 'Email người nhận không được vượt quá 255 ký tự.',
            'recipient_phone.required' => 'Vui lòng nhập số điện thoại người nhận.',
            'recipient_phone.regex' => 'Số điện thoại người nhận không hợp lệ.',
            'province.required' => 'Vui lòng nhập tỉnh hoặc thành phố.',
            'province.string' => 'Tỉnh hoặc thành phố phải là chuỗi ký tự.',
            'province.max' => 'Tỉnh hoặc thành phố không được vượt quá 100 ký tự.',
            'district.required' => 'Vui lòng nhập quận hoặc huyện.',
            'district.string' => 'Quận hoặc huyện phải là chuỗi ký tự.',
            'district.max' => 'Quận hoặc huyện không được vượt quá 100 ký tự.',
            'ward.required' => 'Vui lòng nhập phường hoặc xã.',
            'ward.string' => 'Phường hoặc xã phải là chuỗi ký tự.',
            'ward.max' => 'Phường hoặc xã không được vượt quá 100 ký tự.',
            'address_line.required' => 'Vui lòng nhập địa chỉ chi tiết.',
            'address_line.string' => 'Địa chỉ chi tiết phải là chuỗi ký tự.',
            'address_line.max' => 'Địa chỉ chi tiết không được vượt quá 500 ký tự.',
            'coupon_code.string' => 'Mã giảm giá phải là chuỗi ký tự.',
            'coupon_code.max' => 'Mã giảm giá không được vượt quá 80 ký tự.',
        ];
    }

    public function recipient(): CheckoutRecipient
    {
        $data = $this->validated();

        return new CheckoutRecipient(
            $data['recipient_name'],
            $data['recipient_email'],
            $data['recipient_phone'],
            $data['province'],
            $data['district'],
            $data['ward'],
            $data['address_line'],
        );
    }

    public function couponCode(): ?string
    {
        $code = $this->validated('coupon_code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** @return array<string, mixed> */
    public function quoteInput(): array
    {
        return $this->safe()->only(self::ALLOWED_FIELDS);
    }
}
