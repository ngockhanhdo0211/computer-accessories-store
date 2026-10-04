<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GetSupportMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['before_id' => ['nullable', 'regex:/^[1-9][0-9]*$/'], 'after_id' => ['nullable', 'regex:/^[1-9][0-9]*$/']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('before_id') && $this->filled('after_id')) {
                $validator->errors()->add('cursor', 'Không thể dùng before_id và after_id cùng lúc.');
            }
            foreach (['before_id', 'after_id'] as $cursor) {
                if ($this->filled($cursor) && $this->exceedsPhpInteger($this->query($cursor))) {
                    $validator->errors()->add($cursor, 'ID phân trang vượt giới hạn cho phép.');
                }
            }
            if (array_diff(array_keys($this->query()), ['before_id', 'after_id']) !== []) {
                $validator->errors()->add('request', 'Yêu cầu chứa tham số không được phép.');
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
