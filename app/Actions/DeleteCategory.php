<?php

namespace App\Actions;

use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteCategory
{
    public function handle(Category $category): void
    {
        try {
            DB::transaction(function () use ($category) {
                $category = Category::query()->lockForUpdate()->findOrFail($category->id);

                if ($category->children()->exists()) {
                    throw ValidationException::withMessages(['category' => 'Không thể xóa danh mục đang có danh mục con.']);
                }

                $category->delete();
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isForeignKeyViolation($exception)) {
                throw ValidationException::withMessages(['category' => 'Không thể xóa danh mục đang được sử dụng. Hãy ẩn danh mục thay thế.']);
            }

            if (DB::getDriverName() === 'mysql' && in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw ValidationException::withMessages(['category' => 'Danh mục đang được cập nhật đồng thời. Vui lòng thử lại.']);
            }

            throw $exception;
        }
    }

    private function isForeignKeyViolation(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return match (DB::getDriverName()) {
            'mysql' => $driverCode === 1451,
            'sqlite' => $driverCode === 19 && str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed'),
            default => false,
        };
    }
}
