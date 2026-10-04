<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class SendSupportMessageRequest extends FormRequest
{
    protected $errorBag = 'supportMessage';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_message_key' => is_string($this->input('client_message_key')) ? Str::lower(Str::trim($this->input('client_message_key'))) : $this->input('client_message_key'),
            'content' => is_string($this->input('content')) && mb_check_encoding($this->input('content'), 'UTF-8')
                ? Str::trim($this->input('content')) : $this->input('content'),
        ]);
    }

    public function rules(): array
    {
        return ['client_message_key' => ['required', 'uuid'],
            'content' => ['required', 'string', 'max:2000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u']];
    }

    public function messages(): array
    {
        return ['client_message_key.required' => 'Thiếu mã chống gửi lặp.', 'client_message_key.uuid' => 'Mã chống gửi lặp không hợp lệ.',
            'content.required' => 'Vui lòng nhập nội dung tin nhắn.', 'content.string' => 'Nội dung tin nhắn không hợp lệ.',
            'content.max' => 'Tin nhắn không được vượt quá 2.000 ký tự.', 'content.not_regex' => 'Tin nhắn chứa ký tự điều khiển không hợp lệ.'];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => array_diff(array_keys($this->all()), ['_token', 'client_message_key', 'content']) !== []
            ? $validator->errors()->add('request', 'Yêu cầu chứa trường không được phép.') : null];
    }
}
