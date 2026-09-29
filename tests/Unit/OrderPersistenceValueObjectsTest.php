<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\ValueObjects\OrderItemSnapshot;
use App\ValueObjects\OrderSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OrderPersistenceValueObjectsTest extends TestCase
{
    public function test_order_status_has_only_agreed_values_and_edges(): void
    {
        $this->assertSame([
            'da_dat',
            'cho_chuyen_phat',
            'dang_trung_chuyen',
            'da_giao',
            'da_huy',
        ], array_column(OrderStatus::cases(), 'value'));
        $this->assertTrue(OrderStatus::Placed->canTransitionTo(OrderStatus::AwaitingHandoff));
        $this->assertTrue(OrderStatus::Placed->canTransitionTo(OrderStatus::Cancelled));
        $this->assertTrue(OrderStatus::InTransit->canTransitionTo(OrderStatus::Delivered));
        $this->assertFalse(OrderStatus::Delivered->canTransitionTo(OrderStatus::Placed));
        $this->assertFalse(OrderStatus::Cancelled->canTransitionTo(OrderStatus::Placed));
        $this->assertSame(['cod', 'vnpay'], array_column(PaymentMethod::cases(), 'value'));
    }

    public function test_order_snapshot_accepts_reconciled_scalar_data_and_coupon_shape(): void
    {
        $snapshot = new OrderSnapshot(
            'Nguyen Minh Anh',
            'receiver@example.test',
            '0912345678',
            '12 Tran Thai Tong, Cau Giay, Ha Noi',
            'Ha Noi',
            [
                'scope' => 'cart',
                'code' => 'SAVE10',
                'eligible_subtotal_vnd' => 200_000,
                'value' => 10,
                'coupon_id' => 1,
                'type' => 'percent',
            ],
            200_000,
            20_000,
            30_000,
            0,
            210_000,
        );

        $this->assertSame(210_000, $snapshot->totalVnd);
        $this->assertSame('SAVE10', $snapshot->coupon['code']);
    }

    public function test_snapshot_validators_reject_bad_shape_and_non_reconciling_money(): void
    {
        foreach ([
            fn () => new OrderSnapshot(
                'Receiver',
                'receiver@example.test',
                '0912345678',
                'Address',
                'Ha Noi',
                ['password' => 'secret'],
                100,
                0,
                10,
                0,
                110,
            ),
            fn () => new OrderSnapshot(
                'Receiver',
                'receiver@example.test',
                '0912345678',
                'Address',
                'Ha Noi',
                [
                    'coupon_id' => 1,
                    'code' => 'SAVE10',
                    'type' => 'percent',
                    'scope' => 'cart',
                    'value' => 10,
                    'eligible_subtotal_vnd' => 100,
                    'meta' => ['PaSsWoRd' => 'secret'],
                ],
                100,
                10,
                0,
                0,
                90,
            ),
            fn () => new OrderSnapshot(
                'Receiver'.chr(0xC3),
                'receiver@example.test',
                '0912345678',
                'Address',
                'Ha Noi',
                null,
                100,
                0,
                0,
                0,
                100,
            ),
            fn () => new OrderSnapshot(
                'Receiver',
                'receiver@example.test',
                '0912345678',
                'Address',
                'Ha Noi',
                null,
                100,
                20,
                10,
                0,
                100,
            ),
            fn () => new OrderItemSnapshot('Product', 'SKU-1', 2, 100, 200, 201, -1),
            fn () => new OrderItemSnapshot('Product', 'SKU-1', 2, 100, 199, 0, 199),
            fn () => new OrderItemSnapshot('Product', 'SKU-1', '2', 100, 200, 0, 200),
            fn () => new OrderItemSnapshot('Product', 'SKU-1', true, 100, 100, 0, 100),
            fn () => new OrderSnapshot(
                'Receiver',
                'receiver@example.test',
                '0912345678',
                'Address',
                'Ha Noi',
                null,
                '100',
                0,
                0,
                0,
                100,
            ),
            fn () => new OrderSnapshot(
                'Receiver',
                'receiver@example.test',
                '0912345678',
                'Address',
                'Ha Noi',
                null,
                PHP_INT_MAX,
                PHP_INT_MAX,
                PHP_INT_MAX,
                PHP_INT_MAX,
                0,
            ),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Invalid Order snapshot was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
