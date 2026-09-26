<?php

namespace App\Http\Requests;

use App\Enums\ProductVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => is_string($this->input('search')) ? trim($this->input('search')) : null,
            'category' => $this->integerOrNull($this->input('category')),
            'brand' => $this->integerOrNull($this->input('brand')),
            'visibility' => in_array($this->input('visibility'), array_column(ProductVisibility::cases(), 'value'), true)
                ? $this->input('visibility')
                : null,
            'sort' => in_array($this->input('sort'), ['name', 'newest', 'price_asc', 'price_desc'], true)
                ? $this->input('sort')
                : 'name',
        ]);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'brand' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'visibility' => ['nullable', Rule::enum(ProductVisibility::class)],
            'sort' => ['required', Rule::in(['name', 'newest', 'price_asc', 'price_desc'])],
        ];
    }

    private function integerOrNull(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    }
}
