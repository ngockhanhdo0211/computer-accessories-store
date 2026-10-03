<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReconcileRefundRequest extends FormRequest
{
    protected $errorBag = 'reconcileRefund';

    public function authorize(): bool
    {
        return UserRole::tryFrom((string) $this->user()?->getRawOriginal('role')) === UserRole::Admin;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'event_key' => is_string($this->input('event_key')) ? trim($this->input('event_key')) : $this->input('event_key'),
            'note' => is_string($this->input('note')) ? trim($this->input('note')) : $this->input('note'),
            'gateway_reference' => is_string($this->input('gateway_reference'))
                ? (trim($this->input('gateway_reference')) === '' ? null : trim($this->input('gateway_reference')))
                : $this->input('gateway_reference'),
        ]);
    }

    public function rules(): array
    {
        return [
            'event_key' => ['required', 'uuid'],
            'outcome' => ['required', Rule::in(['succeeded', 'failed'])],
            'note' => ['required', 'string', 'max:500'],
            'gateway_reference' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['_token', '_method', 'event_key', 'outcome', 'note', 'gateway_reference']) !== []) {
                $validator->errors()->add('request', 'Yêu cầu chứa trường không được phép.');
            }
        }];
    }
}
