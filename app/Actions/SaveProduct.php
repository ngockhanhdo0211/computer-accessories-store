<?php

namespace App\Actions;

use App\Enums\ProductVisibility;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveProduct
{
    public function handle(array $input, ?Product $product = null): Product
    {
        $validated = Validator::make($input, [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')],
            'name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/u'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'sku' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/'],
            'short_description' => ['required', 'string', 'max:1000', 'not_regex:/^\s*$/u'],
            'description' => ['required', 'string', 'max:20000', 'not_regex:/^\s*$/u'],
            'price_vnd' => ['required', 'integer', 'min:1'],
            'sale_price_vnd' => ['nullable', 'integer', 'min:1', 'lt:price_vnd'],
            'visibility' => ['required', Rule::enum(ProductVisibility::class)],
        ])->validate();

        try {
            return DB::transaction(function () use ($validated, $product) {
                $product = $product === null
                    ? new Product
                    : Product::query()->lockForUpdate()->findOrFail($product->id);

                $product->fill($validated);
                $product->save();

                return $product;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $field = $this->duplicateField($exception, $validated, $product);

            if ($field !== null) {
                throw ValidationException::withMessages([
                    $field => $field === 'sku' ? 'SKU này đã được sử dụng.' : 'Slug này đã được sử dụng.',
                ]);
            }

            throw $exception;
        }
    }

    private function duplicateField(UniqueConstraintViolationException $exception, array $validated, ?Product $product): ?string
    {
        $details = strtolower($exception->getMessage());

        foreach (['sku', 'slug'] as $field) {
            $matchesConstraint = str_contains($details, "products_{$field}_unique")
                || str_contains($details, "products.{$field}");

            if ($matchesConstraint && Product::query()->where($field, $validated[$field])
                ->when($product?->exists, fn ($query) => $query->whereKeyNot($product->id))
                ->exists()) {
                return $field;
            }
        }

        return null;
    }
}
