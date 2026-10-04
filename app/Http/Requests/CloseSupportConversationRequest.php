<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CloseSupportConversationRequest extends FormRequest
{
    protected $errorBag = 'closeSupport';

    public function authorize(): bool
    {
        return $this->user() !== null;
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
        return [fn (Validator $validator) => array_diff(array_keys($this->all()), ['_token', '_method', 'event_key']) !== []
            ? $validator->errors()->add('request', 'Yêu cầu chứa trường không được phép.') : null];
    }
}
