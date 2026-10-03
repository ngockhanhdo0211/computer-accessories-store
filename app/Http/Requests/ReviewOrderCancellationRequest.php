<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReviewOrderCancellationRequest extends FormRequest
{
    protected $errorBag = 'approveCancellation';

    public function authorize(): bool
    {
        return in_array(UserRole::tryFrom((string) $this->user()?->getRawOriginal('role')), [UserRole::Employee, UserRole::Admin], true);
    }

    protected function prepareForValidation(): void
    {
        $this->errorBag = $this->routeIs('*.order-cancellation-requests.reject') ? 'rejectCancellation' : 'approveCancellation';
        $this->merge([
            'event_key' => is_string($this->input('event_key')) ? trim($this->input('event_key')) : $this->input('event_key'),
            'note' => is_string($this->input('note')) ? (trim($this->input('note')) === '' ? null : trim($this->input('note'))) : $this->input('note'),
        ]);
    }

    public function rules(): array
    {
        return ['event_key' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:500', 'not_regex:/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u']];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => array_diff(array_keys($this->all()), ['_token', '_method', 'event_key', 'note']) !== [] ? $validator->errors()->add('request', 'Yêu cầu chứa trường không được phép.') : null];
    }
}
