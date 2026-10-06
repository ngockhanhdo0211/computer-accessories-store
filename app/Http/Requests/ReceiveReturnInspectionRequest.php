<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReceiveReturnInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array(UserRole::tryFrom((string) $this->user()?->getRawOriginal('role')), [UserRole::Employee, UserRole::Admin], true);
    }

    protected function prepareForValidation(): void
    {
        $this->errorBag = 'receive-'.$this->route('orderItem');
        $note = $this->input('note');
        $this->merge([
            'event_key' => is_string($this->input('event_key')) ? strtolower(trim($this->input('event_key'))) : $this->input('event_key'),
            'note' => is_string($note) ? (trim($note) === '' ? null : trim($note)) : $note,
        ]);
    }

    public function rules(): array
    {
        return ['event_key' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:500']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['_token', 'event_key', 'note']) !== []) {
                $validator->errors()->add('request', 'Yêu cầu chứa trường không được phép.');
            }
        }];
    }
}
