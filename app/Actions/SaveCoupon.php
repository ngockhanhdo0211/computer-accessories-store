<?php

namespace App\Actions;

use App\Actions\Concerns\AuthorizesActiveAdmin;
use App\Enums\CouponScope;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\User;
use App\Support\CouponDefinitionValidator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveCoupon
{
    use AuthorizesActiveAdmin;

    public function __construct(private readonly CouponDefinitionValidator $validator) {}

    public function handle(array $input, User $actor, ?Coupon $coupon = null): Coupon
    {
        $this->assertActiveAdmin($actor);
        $validated = $this->validator->validate($input);

        try {
            return DB::transaction(function () use ($validated, $actor, $coupon) {
                $locked = $coupon === null
                    ? new Coupon
                    : Coupon::query()->lockForUpdate()->findOrFail($coupon->id);
                $creating = ! $locked->exists;
                $before = $creating ? null : $this->snapshot($locked);

                if (! $creating) {
                    $this->deleteTargets($locked->id);
                }

                $locked->fill(collect($validated)->except('target_ids')->all());
                $locked->save();
                $this->insertTargets($locked, $validated['target_ids'] ?? []);

                (new AuditLog)->forceFill([
                    'actor_id' => $actor->id,
                    'action' => $creating ? 'coupon.created' : 'coupon.updated',
                    'subject_type' => Coupon::class,
                    'subject_id' => $locked->id,
                    'before_json' => $before,
                    'after_json' => $this->snapshot($locked),
                    'created_at' => now(),
                ])->save();

                return $locked->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $details = strtolower($exception->getMessage());
            if ((str_contains($details, 'coupons_code_unique') || str_contains($details, 'coupons.code'))
                && Coupon::query()->where('code', $validated['code'])
                    ->when($coupon?->exists, fn ($query) => $query->whereKeyNot($coupon->id))->exists()) {
                throw ValidationException::withMessages(['code' => 'Mã giảm giá này đã tồn tại.']);
            }

            throw $exception;
        }
    }

    /** @param array<int, int> $targetIds */
    private function insertTargets(Coupon $coupon, array $targetIds): void
    {
        $tableAndColumn = match ($coupon->scope) {
            CouponScope::Cart => null,
            CouponScope::Product => ['coupon_products', 'product_id'],
            CouponScope::Category => ['coupon_categories', 'category_id'],
            CouponScope::Brand => ['coupon_brands', 'brand_id'],
        };

        if ($tableAndColumn === null) {
            return;
        }

        [$table, $column] = $tableAndColumn;
        DB::table($table)->insert(array_map(
            fn (int $id) => ['coupon_id' => $coupon->id, $column => $id],
            $targetIds,
        ));
    }

    private function deleteTargets(int $couponId): void
    {
        foreach (['coupon_products', 'coupon_categories', 'coupon_brands'] as $table) {
            DB::table($table)->where('coupon_id', $couponId)->delete();
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Coupon $coupon): array
    {
        return [
            'code' => $coupon->code,
            'type' => $coupon->type->value,
            'scope' => $coupon->scope->value,
            'value' => $coupon->value,
            'min_subtotal_vnd' => $coupon->min_subtotal_vnd,
            'required_tier' => $coupon->required_tier?->value,
            'max_uses' => $coupon->max_uses,
            'max_uses_per_user' => $coupon->max_uses_per_user,
            'starts_at' => $coupon->starts_at->utc()->format('Y-m-d H:i:s.u'),
            'ends_at' => $coupon->ends_at->utc()->format('Y-m-d H:i:s.u'),
            'is_active' => $coupon->is_active,
            'target_ids' => $coupon->targetIds(),
        ];
    }
}
