<?php

namespace App\Actions;

use App\Models\Brand;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveBrand
{
    public function handle(array $input, ?Brand $brand = null): Brand
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/u'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'is_visible' => ['required', 'boolean'],
        ])->validate();

        try {
            return DB::transaction(function () use ($validated, $brand) {
                $brand = $brand === null
                    ? new Brand
                    : Brand::query()->lockForUpdate()->findOrFail($brand->id);
                $brand->fill([
                    'name' => $validated['name'],
                    'slug' => $validated['slug'],
                    'is_visible' => $validated['is_visible'],
                ]);
                $brand->save();

                return $brand;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $details = strtolower($exception->getMessage());

            if ((str_contains($details, 'brands_slug_unique') || str_contains($details, 'brands.slug'))
                && Brand::query()->where('slug', $validated['slug'])
                    ->when($brand?->exists, fn ($query) => $query->whereKeyNot($brand->id))->exists()) {
                throw ValidationException::withMessages(['slug' => 'Slug này đã được sử dụng.']);
            }

            throw $exception;
        }
    }
}
