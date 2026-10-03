<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreOrderCancellationRequest extends FormRequest
{
    protected $errorBag = 'cancellationRequest';

    public function authorize(): bool
    {
        return UserRole::tryFrom((string) $this->user()?->getRawOriginal('role')) === UserRole::Customer;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'request_key' => is_string($this->input('request_key')) ? trim($this->input('request_key')) : $this->input('request_key'),
            'reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason'),
        ]);
    }

    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:500', 'not_regex:/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u']];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => array_diff(array_keys($this->all()), ['_token', 'request_key', 'reason']) !== [] ? $validator->errors()->add('request', 'Yêu cầu chứa trường không được phép.') : null];
    }
}
