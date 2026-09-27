<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateShippingRate
{
    public function handle(ShippingRate $shippingRate, User $admin, mixed $feeVnd): ShippingRate
    {
        $this->assertAdmin($admin);

        $validated = Validator::make(['fee_vnd' => $feeVnd], [
            'fee_vnd' => ['bail', 'required', 'regex:/^\d+$/', 'integer', 'min:0', 'max:9223372036854775807'],
        ])->validate();

        return DB::transaction(function () use ($shippingRate, $admin, $validated) {
            $locked = ShippingRate::query()->lockForUpdate()->findOrFail($shippingRate->id);
            $nextFee = (int) $validated['fee_vnd'];

            if ($locked->fee_vnd === $nextFee) {
                return $locked;
            }

            $regionKey = (string) $locked->getRawOriginal('region_key');
            $before = [
                'region_key' => $regionKey,
                'fee_vnd' => $locked->fee_vnd,
                'updated_by' => $locked->updated_by,
            ];

            $locked->forceFill([
                'fee_vnd' => $nextFee,
                'updated_by' => $admin->id,
            ])->save();

            (new AuditLog)->forceFill([
                'actor_id' => $admin->id,
                'action' => 'shipping_rate.updated',
                'subject_type' => ShippingRate::class,
                'subject_id' => $locked->id,
                'before_json' => $before,
                'after_json' => [
                    'region_key' => $regionKey,
                    'fee_vnd' => $locked->fee_vnd,
                    'updated_by' => $locked->updated_by,
                ],
                'created_at' => now(),
            ])->save();

            return $locked;
        }, 3);
    }

    private function assertAdmin(User $user): void
    {
        $role = UserRole::tryFrom((string) $user->getRawOriginal('role'));
        $status = UserStatus::tryFrom((string) $user->getRawOriginal('status'));

        if ($role !== UserRole::Admin || $status !== UserStatus::Active) {
            throw ValidationException::withMessages(['authorization' => 'Tài khoản không có quyền cập nhật phí vận chuyển.']);
        }
    }
}
