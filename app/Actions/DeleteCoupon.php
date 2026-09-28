<?php

namespace App\Actions;

use App\Actions\Concerns\AuthorizesActiveAdmin;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeleteCoupon
{
    use AuthorizesActiveAdmin;

    public function handle(Coupon $coupon, User $actor): void
    {
        $this->assertActiveAdmin($actor);

        try {
            DB::transaction(function () use ($coupon, $actor): void {
                $locked = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);

                if (Schema::hasTable('coupon_usages') && DB::table('coupon_usages')->where('coupon_id', $locked->id)->exists()) {
                    throw ValidationException::withMessages(['coupon' => 'Mã đã phát sinh lượt sử dụng. Hãy ngừng kích hoạt thay vì xóa.']);
                }

                $before = [
                    'code' => $locked->code,
                    'type' => $locked->type->value,
                    'scope' => $locked->scope->value,
                    'is_active' => $locked->is_active,
                    'target_ids' => $locked->targetIds(),
                ];

                foreach (['coupon_products', 'coupon_categories', 'coupon_brands'] as $table) {
                    DB::table($table)->where('coupon_id', $locked->id)->delete();
                }
                $locked->delete();

                (new AuditLog)->forceFill([
                    'actor_id' => $actor->id,
                    'action' => 'coupon.deleted',
                    'subject_type' => Coupon::class,
                    'subject_id' => $coupon->id,
                    'before_json' => $before,
                    'after_json' => null,
                    'created_at' => now(),
                ])->save();
            }, 3);
        } catch (QueryException $exception) {
            $details = strtolower($exception->getMessage());
            $knownUsage = Schema::hasTable('coupon_usages')
                && DB::table('coupon_usages')->where('coupon_id', $coupon->id)->exists();
            $knownReference = DB::getDriverName() === 'mysql'
                ? (int) ($exception->errorInfo[1] ?? 0) === 1451 && str_contains($details, 'coupons')
                : DB::getDriverName() === 'sqlite' && $knownUsage && str_contains($details, 'foreign key constraint failed');

            if ($knownReference) {
                throw ValidationException::withMessages(['coupon' => 'Mã đang được tham chiếu. Hãy ngừng kích hoạt thay vì xóa.']);
            }

            throw $exception;
        }
    }
}
