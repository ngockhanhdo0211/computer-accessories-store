<?php

namespace App\Support;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Enums\MembershipLevel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CouponDefinitionValidator
{
    /** @return array<string, mixed> */
    public function validate(array $input): array
    {
        $scope = CouponScope::tryFrom((string) ($input['scope'] ?? ''));
        $type = CouponType::tryFrom((string) ($input['type'] ?? ''));
        $targetTable = match ($scope) {
            CouponScope::Product => 'products',
            CouponScope::Category => 'categories',
            CouponScope::Brand => 'brands',
            default => null,
        };

        $rules = [
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'],
            'type' => ['required', Rule::enum(CouponType::class)],
            'scope' => ['required', Rule::enum(CouponScope::class)],
            'value' => ['required', 'integer', 'min:0', 'max:9223372036854775807'],
            'min_subtotal_vnd' => ['required', 'integer', 'min:0', 'max:9223372036854775807'],
            'required_tier' => ['nullable', Rule::enum(MembershipLevel::class)],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'max_uses_per_user' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'is_active' => ['required', 'boolean'],
            'target_ids' => [
                Rule::requiredIf($scope !== null && $scope !== CouponScope::Cart),
                Rule::prohibitedIf($scope === CouponScope::Cart),
                'array',
                'min:1',
            ],
            'target_ids.*' => array_values(array_filter([
                'integer',
                'distinct',
                $targetTable === null ? null : Rule::exists($targetTable, 'id'),
            ])),
        ];

        $validator = Validator::make($input, $rules, [
            'code.required' => 'Vui lòng nhập mã giảm giá.',
            'code.regex' => 'Mã chỉ gồm chữ in hoa, số, dấu gạch ngang hoặc gạch dưới.',
            'type.required' => 'Vui lòng chọn loại ưu đãi.',
            'scope.required' => 'Vui lòng chọn phạm vi áp dụng.',
            'value.required' => 'Vui lòng nhập giá trị ưu đãi.',
            'value.integer' => 'Giá trị ưu đãi phải là số nguyên.',
            'value.min' => 'Giá trị ưu đãi không được nhỏ hơn 0.',
            'value.max' => 'Giá trị ưu đãi vượt quá giới hạn cho phép.',
            'code.max' => 'Mã giảm giá không được vượt quá 80 ký tự.',
            'type.enum' => 'Loại ưu đãi không hợp lệ.',
            'scope.enum' => 'Phạm vi áp dụng không hợp lệ.',
            'min_subtotal_vnd.required' => 'Vui lòng nhập tổng tối thiểu đủ điều kiện.',
            'min_subtotal_vnd.integer' => 'Tổng tối thiểu phải là số nguyên VND.',
            'min_subtotal_vnd.min' => 'Tổng tối thiểu không được âm.',
            'min_subtotal_vnd.max' => 'Tổng tối thiểu vượt quá giới hạn cho phép.',
            'required_tier.enum' => 'Hạng thành viên không hợp lệ.',
            'max_uses.integer' => 'Tổng lượt tối đa phải là số nguyên.',
            'max_uses.min' => 'Tổng lượt tối đa phải ít nhất là 1.',
            'max_uses.max' => 'Tổng lượt tối đa vượt quá giới hạn cho phép.',
            'max_uses_per_user.integer' => 'Lượt tối đa mỗi khách phải là số nguyên.',
            'max_uses_per_user.min' => 'Lượt tối đa mỗi khách phải ít nhất là 1.',
            'max_uses_per_user.max' => 'Lượt tối đa mỗi khách vượt quá giới hạn cho phép.',
            'starts_at.required' => 'Vui lòng chọn thời điểm bắt đầu.',
            'starts_at.date_format' => 'Thời điểm bắt đầu không đúng định dạng.',
            'ends_at.required' => 'Vui lòng chọn thời điểm kết thúc.',
            'ends_at.date_format' => 'Thời điểm kết thúc không đúng định dạng.',
            'is_active.boolean' => 'Trạng thái kích hoạt không hợp lệ.',
            'target_ids.array' => 'Danh sách mục tiêu không hợp lệ.',
            'target_ids.min' => 'Vui lòng chọn ít nhất một mục tiêu.',
            'target_ids.*.integer' => 'Mục tiêu đã chọn không hợp lệ.',
            'target_ids.*.distinct' => 'Mỗi mục tiêu chỉ được chọn một lần.',
            'ends_at.after' => 'Thời điểm kết thúc phải sau thời điểm bắt đầu.',
            'target_ids.required' => 'Vui lòng chọn ít nhất một mục tiêu đúng với phạm vi.',
            'target_ids.prohibited' => 'Phạm vi toàn giỏ hàng không được có mục tiêu.',
            'target_ids.*.exists' => 'Một mục tiêu đã chọn không còn tồn tại.',
        ]);

        $validator->after(function ($validator) use ($input, $type): void {
            if (! empty($input['wrong_target_scope'])) {
                $validator->errors()->add('target_ids', 'Chỉ được chọn mục tiêu thuộc đúng loại scope.');
            }

            if ($type === null || ! isset($input['value']) || filter_var($input['value'], FILTER_VALIDATE_INT) === false) {
                return;
            }

            $value = (int) $input['value'];
            if ($type === CouponType::Percent && ($value < 1 || $value > 100)) {
                $validator->errors()->add('value', 'Ưu đãi phần trăm phải từ 1 đến 100.');
            } elseif ($type === CouponType::Fixed && $value < 1) {
                $validator->errors()->add('value', 'Ưu đãi cố định phải lớn hơn 0 VND.');
            } elseif ($type === CouponType::FreeShipping && $value !== 0) {
                $validator->errors()->add('value', 'Miễn phí vận chuyển phải có giá trị bằng 0.');
            }
        });

        try {
            return $validator->validate();
        } catch (ValidationException $exception) {
            throw $exception;
        }
    }
}
