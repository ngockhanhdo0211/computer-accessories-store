<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = UserRole::tryFrom((string) $this->user()?->getRawOriginal('role'));

        return $role !== null && in_array($role, [UserRole::Customer, UserRole::Employee, UserRole::Admin], true);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->trimmedStringOrNull($this->input('search')),
            'status' => $this->enumValueOrNull($this->input('status'), OrderStatus::class),
            'payment_status' => $this->enumValueOrNull($this->input('payment_status'), PaymentStatus::class),
            'payment_method' => $this->enumValueOrNull($this->input('payment_method'), PaymentMethod::class),
            'date_from' => $this->trimmedStringOrNull($this->input('date_from')),
            'date_to' => $this->trimmedStringOrNull($this->input('date_to')),
            'sort' => in_array($this->input('sort'), ['newest', 'oldest', 'total_asc', 'total_desc'], true)
                ? $this->input('sort')
                : 'newest',
        ]);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sort' => ['required', Rule::in(['newest', 'oldest', 'total_asc', 'total_desc'])],
        ];
    }

    public function messages(): array
    {
        return [
            'search.max' => 'Từ khóa tìm kiếm không được vượt quá 100 ký tự.',
            'date_from.date_format' => 'Ngày bắt đầu phải đúng định dạng ngày/tháng/năm.',
            'date_to.date_format' => 'Ngày kết thúc phải đúng định dạng ngày/tháng/năm.',
            'date_to.after_or_equal' => 'Ngày kết thúc phải bằng hoặc sau ngày bắt đầu.',
        ];
    }

    /** @param class-string<\BackedEnum> $enum */
    private function enumValueOrNull(mixed $value, string $enum): ?string
    {
        return is_string($value) && $enum::tryFrom($value) !== null ? $value : null;
    }

    private function trimmedStringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
