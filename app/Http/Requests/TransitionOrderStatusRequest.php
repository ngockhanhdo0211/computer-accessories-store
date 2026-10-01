<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = UserRole::tryFrom((string) $this->user()?->getRawOriginal('role'));

        return in_array($role, [UserRole::Employee, UserRole::Admin], true);
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        $this->merge([
            'event_key' => is_string($this->input('event_key')) ? trim($this->input('event_key')) : null,
            'reason' => is_string($reason) ? (trim($reason) === '' ? null : trim($reason)) : $reason,
        ]);
    }

    public function rules(): array
    {
        return [
            'target_status' => [
                'required',
                Rule::in([
                    OrderStatus::AwaitingHandoff->value,
                    OrderStatus::InTransit->value,
                ]),
            ],
            'event_key' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_status.required' => 'Thiếu trạng thái đích.',
            'target_status.in' => 'Trạng thái đích không thuộc Order Transit Progression Phase 1.',
            'event_key.required' => 'Thiếu mã chống lặp cho thao tác.',
            'event_key.uuid' => 'Mã chống lặp không hợp lệ. Hãy tải lại trang và thử lại.',
            'reason.string' => 'Ghi chú phải là chuỗi ký tự.',
            'reason.max' => 'Ghi chú không được vượt quá 500 ký tự.',
        ];
    }

    public function targetStatus(): OrderStatus
    {
        return OrderStatus::from($this->validated('target_status'));
    }

    public function eventKey(): string
    {
        return $this->validated('event_key');
    }
}
