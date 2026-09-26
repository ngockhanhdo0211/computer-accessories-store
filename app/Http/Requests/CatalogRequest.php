<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => is_string($this->input('search')) ? trim($this->input('search')) : null,
            'category' => $this->integerOrNull($this->input('category')),
            'brand' => $this->integerOrNull($this->input('brand')),
            'sort' => in_array($this->input('sort'), ['newest', 'price_asc', 'price_desc', 'name'], true)
                ? $this->input('sort')
                : 'newest',
        ]);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer'],
            'brand' => ['nullable', 'integer'],
            'sort' => ['required', Rule::in(['newest', 'price_asc', 'price_desc', 'name'])],
        ];
    }

    private function integerOrNull(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    }
}
