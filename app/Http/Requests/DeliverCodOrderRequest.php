<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DeliverCodOrderRequest extends FormRequest
{
    protected $errorBag = 'deliverOrder';

    public function authorize(): bool
    {
        return in_array(UserRole::tryFrom((string) $this->user()?->getRawOriginal('role')), [UserRole::Employee, UserRole::Admin], true);
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');
        $this->merge([
            'event_key' => is_string($this->input('event_key')) ? trim($this->input('event_key')) : $this->input('event_key'),
            'reason' => is_string($reason) ? (trim($reason) === '' ? null : trim($reason)) : $reason,
        ]);
    }

    public function rules(): array
    {
        return [
            'event_key' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['_token', '_method', 'event_key', 'reason']) !== []) {
                $validator->errors()->add('request', 'Yêu cầu chứa trường không được phép.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'event_key.required' => 'Thiếu mã chống lặp cho thao tác.',
            'event_key.uuid' => 'Mã chống lặp không hợp lệ. Hãy tải lại trang và thử lại.',
            'reason.string' => 'Ghi chú giao hàng phải là chuỗi ký tự.',
            'reason.max' => 'Ghi chú giao hàng không được vượt quá 500 ký tự.',
        ];
    }
}
