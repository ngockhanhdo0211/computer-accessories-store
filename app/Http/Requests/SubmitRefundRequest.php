<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SubmitRefundRequest extends FormRequest
{
    protected $errorBag = 'submitRefund';

    public function authorize(): bool
    {
        return UserRole::tryFrom((string) $this->user()?->getRawOriginal('role')) === UserRole::Admin;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['event_key' => is_string($this->input('event_key')) ? trim($this->input('event_key')) : $this->input('event_key')]);
    }

    public function rules(): array
    {
        return ['event_key' => ['required', 'uuid']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['_token', 'event_key']) !== []) {
                $validator->errors()->add('request', 'Yêu cầu chứa trường không được phép.');
            }
        }];
    }
}
