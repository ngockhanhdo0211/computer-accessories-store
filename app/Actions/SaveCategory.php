<?php

namespace App\Actions;

use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveCategory
{
    public function handle(array $input, ?Category $category = null): Category
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/u'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'parent_id' => ['nullable', 'integer'],
            'is_visible' => ['required', 'boolean'],
        ])->validate();

        try {
            return DB::transaction(function () use ($validated, $category) {
                $category = $category === null ? new Category : Category::query()->lockForUpdate()->findOrFail($category->id);
                $parentId = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;
                $this->assertValidParent($category, $parentId);
                $category->fill([
                    'name' => $validated['name'],
                    'slug' => $validated['slug'],
                    'parent_id' => $parentId,
                    'is_visible' => $validated['is_visible'],
                ]);
                $category->save();

                return $category;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $details = strtolower($exception->getMessage());

            if ((str_contains($details, 'categories_slug_unique') || str_contains($details, 'categories.slug'))
                && Category::query()->where('slug', $validated['slug'])
                    ->when($category?->exists, fn ($query) => $query->whereKeyNot($category->id))->exists()) {
                throw ValidationException::withMessages(['slug' => 'Slug này đã được sử dụng.']);
            }

            throw $exception;
        } catch (QueryException $exception) {
            if (DB::getDriverName() === 'mysql' && in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw ValidationException::withMessages(['parent_id' => 'Danh mục đang được cập nhật đồng thời. Vui lòng thử lại.']);
            }

            throw $exception;
        }
    }

    private function assertValidParent(Category $category, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $visited = [];
        $currentId = $parentId;

        while ($currentId !== null) {
            if (($category->exists && $currentId === $category->id) || isset($visited[$currentId])) {
                throw ValidationException::withMessages(['parent_id' => 'Danh mục cha đã chọn sẽ tạo chu trình.']);
            }

            $visited[$currentId] = true;
            $parent = Category::query()->whereKey($currentId)->lockForUpdate()->first(['id', 'parent_id']);

            if ($parent === null) {
                throw ValidationException::withMessages(['parent_id' => 'Danh mục cha không tồn tại.']);
            }

            $currentId = $parent->parent_id;
        }
    }
}
