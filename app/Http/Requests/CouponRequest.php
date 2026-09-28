<?php

namespace App\Http\Requests;

use App\Enums\CouponScope;
use App\Support\CouponDefinitionValidator;
use Illuminate\Foundation\Http\FormRequest;

abstract class CouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $scope = CouponScope::tryFrom(trim((string) $this->input('scope')));
        $groups = [
            CouponScope::Product->value => (array) $this->input('product_ids', []),
            CouponScope::Category->value => (array) $this->input('category_ids', []),
            CouponScope::Brand->value => (array) $this->input('brand_ids', []),
        ];
        $targets = $scope === CouponScope::Cart
            ? array_merge(...array_values($groups))
            : ($scope === null ? null : $groups[$scope->value]);
        $wrongTargetScope = $scope !== null && collect($groups)
            ->except($scope === CouponScope::Cart ? [] : [$scope->value])
            ->flatten()->isNotEmpty();

        $normalized = [
            'code' => mb_strtoupper(trim((string) $this->input('code'))),
            'type' => trim((string) $this->input('type')),
            'scope' => $scope?->value ?? trim((string) $this->input('scope')),
            'value' => trim((string) $this->input('value', '')),
            'min_subtotal_vnd' => trim((string) $this->input('min_subtotal_vnd', '')),
            'required_tier' => $this->filled('required_tier') ? trim((string) $this->input('required_tier')) : null,
            'max_uses' => $this->filled('max_uses') ? trim((string) $this->input('max_uses')) : null,
            'max_uses_per_user' => $this->filled('max_uses_per_user') ? trim((string) $this->input('max_uses_per_user')) : null,
            'starts_at' => trim((string) $this->input('starts_at')),
            'ends_at' => trim((string) $this->input('ends_at')),
            'is_active' => $this->boolean('is_active'),
        ];

        if ($targets !== null && ($scope !== CouponScope::Cart || $targets !== [])) {
            $normalized['target_ids'] = array_values(array_unique((array) $targets, SORT_REGULAR));
        }
        if ($wrongTargetScope) {
            $normalized['wrong_target_scope'] = true;
        }

        $this->replace($normalized);
    }

    public function rules(): array
    {
        return [];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = $this->all();

        return $key === null ? $data : data_get($data, $key, $default);
    }

    protected function passedValidation(): void
    {
        $this->replace(app(CouponDefinitionValidator::class)->validate($this->all()));
    }
}
