<?php

namespace App\Actions;

use App\Models\Brand;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteBrand
{
    public function handle(Brand $brand): void
    {
        try {
            DB::transaction(function () use ($brand) {
                Brand::query()->lockForUpdate()->findOrFail($brand->id)->delete();
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isForeignKeyViolation($exception)) {
                throw ValidationException::withMessages([
                    'brand' => 'Không thể xóa thương hiệu đang được sử dụng. Hãy ẩn thương hiệu thay thế.',
                ]);
            }

            throw $exception;
        }
    }

    private function isForeignKeyViolation(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $details = strtolower($exception->getMessage());

        return match (DB::getDriverName()) {
            'mysql' => $driverCode === 1451
                && str_contains($details, 'foreign key constraint fails')
                && preg_match('/references\s+[`"]?brands[`"]?\s*\(/', $details) === 1,
            'sqlite' => $driverCode === 19 && str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed'),
            default => false,
        };
    }
}
