<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SupportInboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['search' => is_string($this->query('search')) ? trim($this->query('search')) : $this->query('search')]);
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['open', 'closed'])],
            'page' => ['nullable', 'regex:/^[1-9][0-9]*$/']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('page') && $this->exceedsPhpInteger($this->query('page'))) {
                $validator->errors()->add('page', 'Trang vượt giới hạn cho phép.');
            }
            if (array_diff(array_keys($this->query()), ['search', 'status', 'page']) !== []) {
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
