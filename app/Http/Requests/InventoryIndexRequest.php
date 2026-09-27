<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isEmployee() === true || $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => is_string($this->input('search')) ? trim($this->input('search')) : null,
            'stock' => in_array($this->input('stock'), ['in_stock', 'low', 'out'], true) ? $this->input('stock') : null,
            'sort' => in_array($this->input('sort'), ['name', 'stock_asc', 'stock_desc'], true) ? $this->input('sort') : 'name',
        ]);
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'stock' => ['nullable', Rule::in(['in_stock', 'low', 'out'])],
            'sort' => ['required', Rule::in(['name', 'stock_asc', 'stock_desc'])]];
    }
}
