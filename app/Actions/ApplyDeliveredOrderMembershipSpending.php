<?php

namespace App\Actions;

use App\Enums\MembershipLevel;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\MembershipHistory;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ApplyDeliveredOrderMembershipSpending
{
    public function handle(Order $order, ?CarbonInterface $at = null): User
    {
        if (! $order->exists || $order->getKey() === null || $order->user_id === null) {
            throw ValidationException::withMessages([
                'order' => 'Order phải được lưu trước khi áp dụng chi tiêu thành viên.',
            ]);
        }

        $orderId = (int) $order->getKey();
        $customerId = (int) $order->user_id;

        return DB::transaction(function () use ($orderId, $customerId, $at): User {
            // Membership writers lock the customer before Orders so concurrent deliveries
            // for one customer share a stable lock order and a single projection result.
            $customer = User::query()->lockForUpdate()->find($customerId);
            if ($customer === null || UserRole::tryFrom((string) $customer->getRawOriginal('role')) !== UserRole::Customer) {
                throw ValidationException::withMessages([
                    'order' => 'Order không thuộc một Customer hợp lệ.',
                ]);
            }

            $deliveredOrder = Order::query()->lockForUpdate()->find($orderId);
            if ($deliveredOrder === null
                || $deliveredOrder->user_id !== $customer->id
                || $deliveredOrder->status !== OrderStatus::Delivered) {
                throw ValidationException::withMessages([
                    'order' => 'Chỉ Order đã giao của đúng Customer mới được tính chi tiêu thành viên.',
                ]);
            }

            $spendingVnd = 0;
            $orders = DB::table('orders')
                ->where('user_id', $customer->id)
                ->where('status', OrderStatus::Delivered->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'items_subtotal_vnd', 'item_discount_vnd']);

            foreach ($orders as $eligibleOrder) {
                $subtotal = $this->parseVnd($eligibleOrder->items_subtotal_vnd, 'Order items subtotal');
                $discount = $this->parseVnd($eligibleOrder->item_discount_vnd, 'Order item discount');
                if ($discount > $subtotal) {
                    throw new RuntimeException('Order item discount exceeds its items subtotal.');
                }

                $eligibleAmount = $subtotal - $discount;
                if ($eligibleAmount > PHP_INT_MAX - $spendingVnd) {
                    throw new RuntimeException('Membership spending exceeds the supported integer range.');
                }

                $spendingVnd += $eligibleAmount;
            }

            $oldTier = MembershipLevel::tryFrom((string) $customer->getRawOriginal('current_tier'));
            if ($oldTier === null) {
                throw new RuntimeException('Customer membership projection is invalid.');
            }
            $oldSpending = $this->parseVnd($customer->getRawOriginal('membership_spending'), 'Customer membership spending');

            $newTier = MembershipLevel::fromSpendingVnd($spendingVnd);
            if ($oldSpending === $spendingVnd && $oldTier === $newTier) {
                return $customer;
            }

            $customer->forceFill([
                'membership_spending' => $spendingVnd,
                'current_tier' => $newTier,
            ])->save();

            if ($oldTier !== $newTier) {
                (new MembershipHistory)->forceFill([
                    'user_id' => $customer->id,
                    'old_tier' => $oldTier,
                    'new_tier' => $newTier,
                    'spending_vnd' => $spendingVnd,
                    'reason' => 'delivered_order',
                    'requested_by' => null,
                    'created_at' => $at ?? now(),
                ])->save();
            }

            return $customer;
        }, 3);
    }

    private function parseVnd(mixed $value, string $field): int
    {
        if (is_int($value)) {
            if ($value >= 0) {
                return $value;
            }

            throw new RuntimeException($field.' cannot be negative.');
        }

        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new RuntimeException($field.' must be a non-negative integer VND value.');
        }

        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new RuntimeException($field.' exceeds the supported integer range.');
        }

        return (int) $value;
    }
}
