<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MarkSupportReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['message_id' => ['required', 'regex:/^[1-9][0-9]*$/']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('message_id') && $this->exceedsPhpInteger($this->input('message_id'))) {
                $validator->errors()->add('message_id', 'ID tin nhắn vượt giới hạn cho phép.');
            }
            if (array_diff(array_keys($this->all()), ['_token', 'message_id']) !== []) {
                $validator->errors()->add('request', 'Yêu cầu chứa trường không được phép.');
            }
        }];
    }

    private function exceedsPhpInteger(mixed $value): bool
    {
        if (! is_int($value) && ! is_string($value)) {
            return false;
        }
        $candidate = (string) $value;
        $maximum = (string) PHP_INT_MAX;

        return strlen($candidate) > strlen($maximum)
            || (strlen($candidate) === strlen($maximum) && strcmp($candidate, $maximum) > 0);
    }
}
