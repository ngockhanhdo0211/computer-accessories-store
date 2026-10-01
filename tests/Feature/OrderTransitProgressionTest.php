<?php

namespace Tests\Feature;

use App\Actions\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class OrderTransitProgressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_and_admin_can_apply_only_the_two_phase_one_transitions(): void
    {
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $order = $this->placedOrder();

        $firstKey = (string) Str::uuid();
        $this->actingAs($employee)->patch(route('employee.orders.transition', $order->order_code), [
            'target_status' => OrderStatus::AwaitingHandoff->value,
            'event_key' => $firstKey,
            'reason' => '  Đã bàn giao cho đơn vị vận chuyển.  ',
        ])->assertRedirect(route('employee.orders.show', $order->order_code))
            ->assertSessionHas('status', 'Đã cập nhật tiến trình vận chuyển.');

        $this->assertSame(OrderStatus::AwaitingHandoff, $order->refresh()->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Placed->value,
            'to_status' => OrderStatus::AwaitingHandoff->value,
            'actor_id' => $employee->id,
            'reason' => 'Đã bàn giao cho đơn vị vận chuyển.',
            'event_key' => $firstKey,
        ]);

        $secondKey = (string) Str::uuid();
        $this->actingAs($admin)->patch(route('admin.orders.transition', $order->order_code), [
            'target_status' => OrderStatus::InTransit->value,
            'event_key' => $secondKey,
        ])->assertRedirect(route('admin.orders.show', $order->order_code));

        $this->assertSame(OrderStatus::InTransit, $order->refresh()->status);
        $this->assertSame(3, $order->statusHistories()->count());
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $employee->id,
            'action' => 'order.status.transitioned',
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'request_id' => $firstKey,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'order.status.transitioned',
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'request_id' => $secondKey,
        ]);
    }

    public function test_transition_is_idempotent_and_rejects_event_key_reuse_with_different_payload(): void
    {
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $order = $this->placedOrder();
        $eventKey = (string) Str::uuid();
        $action = app(TransitionOrderStatus::class);

        $first = $action->handle($order->order_code, $employee, OrderStatus::AwaitingHandoff, $eventKey, 'Bàn giao');
        $replay = $action->handle($order->order_code, $employee, OrderStatus::AwaitingHandoff, $eventKey, ' Bàn giao ');

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(2, $order->statusHistories()->count());
        $this->assertSame(1, AuditLog::query()->where('request_id', $eventKey)->count());

        foreach ([
            [$admin, OrderStatus::AwaitingHandoff, 'Bàn giao'],
            [$employee, OrderStatus::InTransit, 'Bàn giao'],
            [$employee, OrderStatus::AwaitingHandoff, 'Ghi chú khác'],
        ] as [$actor, $target, $reason]) {
            try {
                $action->handle($order->order_code, $actor, $target, $eventKey, $reason);
                $this->fail('Event key reuse with a different payload must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('event_key', $exception->errors());
            }
        }

        $this->assertSame(2, $order->statusHistories()->count());
        $this->assertSame(1, AuditLog::query()->where('request_id', $eventKey)->count());
    }

    public function test_action_normalizes_direct_input_before_validation_and_persistence(): void
    {
        $employee = User::factory()->employee()->create();
        $order = $this->placedOrder();
        $eventKey = (string) Str::uuid();
        $reason = str_repeat('a', 500);

        app(TransitionOrderStatus::class)->handle(
            $order->order_code,
            $employee,
            OrderStatus::AwaitingHandoff,
            "  {$eventKey}  ",
            "  {$reason}  ",
        );

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'event_key' => $eventKey,
            'reason' => $reason,
        ]);
    }

    public function test_phase_one_rejects_every_edge_outside_the_two_allowed_transitions(): void
    {
        $employee = User::factory()->employee()->create();
        $rejected = [
            OrderStatus::Placed->value => [OrderStatus::Placed, OrderStatus::InTransit, OrderStatus::Delivered, OrderStatus::Cancelled],
            OrderStatus::AwaitingHandoff->value => [OrderStatus::Placed, OrderStatus::AwaitingHandoff, OrderStatus::Delivered, OrderStatus::Cancelled],
            OrderStatus::InTransit->value => OrderStatus::cases(),
            OrderStatus::Delivered->value => OrderStatus::cases(),
            OrderStatus::Cancelled->value => OrderStatus::cases(),
        ];

        foreach ($rejected as $currentValue => $targets) {
            $order = $this->orderAt(OrderStatus::from($currentValue));
            $historyCount = $order->statusHistories()->count();

            foreach ($targets as $target) {
                try {
                    app(TransitionOrderStatus::class)->handle($order->order_code, $employee, $target, (string) Str::uuid());
                    $this->fail("Target {$target->value} must be rejected from {$currentValue}.");
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('target_status', $exception->errors());
                }
            }

            $this->assertSame(OrderStatus::from($currentValue), $order->refresh()->status);
            $this->assertSame($historyCount, $order->statusHistories()->count());
        }

        $placed = $this->placedOrder();
        $this->actingAs($employee)->patch(route('employee.orders.transition', $placed->order_code), [
            'target_status' => OrderStatus::Cancelled->value,
            'event_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('target_status');
    }

    public function test_routes_enforce_authentication_role_and_active_account(): void
    {
        $order = $this->placedOrder();
        $payload = [
            'target_status' => OrderStatus::AwaitingHandoff->value,
            'event_key' => (string) Str::uuid(),
        ];

        $this->patch(route('employee.orders.transition', $order->order_code), $payload)
            ->assertRedirect(route('login'));

        $customer = User::factory()->create();
        $this->actingAs($customer)->patch(route('employee.orders.transition', $order->order_code), $payload)
            ->assertForbidden();

        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $this->actingAs($employee)->patch(route('admin.orders.transition', $order->order_code), $payload)
            ->assertForbidden();
        $this->actingAs($admin)->patch(route('employee.orders.transition', $order->order_code), $payload)
            ->assertForbidden();
        $this->actingAs($employee)->patch(route('employee.orders.transition', '404-CODE'), $payload)
            ->assertNotFound();
        $this->patch(route('employee.orders.transition', (string) $order->id), $payload)
            ->assertNotFound();

        foreach ([User::factory()->employee()->inactive()->create(), User::factory()->admin()->locked()->create()] as $blocked) {
            $route = $blocked->isAdmin() ? 'admin.orders.transition' : 'employee.orders.transition';
            $this->actingAs($blocked)->patch(route($route, $order->order_code), $payload)
                ->assertRedirect(route('login'));
            $this->assertGuest();
        }

        $this->assertSame(OrderStatus::Placed, $order->refresh()->status);
        $this->assertSame(1, $order->statusHistories()->count());
    }

    public function test_action_rejects_inactive_or_wrong_role_even_when_called_outside_http(): void
    {
        $order = $this->placedOrder();

        foreach ([User::factory()->create(), User::factory()->employee()->locked()->create()] as $actor) {
            try {
                app(TransitionOrderStatus::class)->handle(
                    $order->order_code,
                    $actor,
                    OrderStatus::AwaitingHandoff,
                    (string) Str::uuid(),
                );
                $this->fail('The action must enforce its own actor contract.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('authorization', $exception->errors());
            }
        }

        $staleActor = User::factory()->employee()->create();
        User::query()->whereKey($staleActor->id)->update(['status' => 'locked']);
        try {
            app(TransitionOrderStatus::class)->handle(
                $order->order_code,
                $staleActor,
                OrderStatus::AwaitingHandoff,
                (string) Str::uuid(),
            );
            $this->fail('The action must revalidate a stale actor inside the transaction.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authorization', $exception->errors());
        }

        $this->assertSame(OrderStatus::Placed, $order->refresh()->status);
    }

    public function test_transition_rejects_history_drift_and_rolls_back_if_audit_write_fails(): void
    {
        $employee = User::factory()->employee()->create();
        $drifted = Order::factory()->for($this->customer(), 'customer')->create([
            'status' => OrderStatus::AwaitingHandoff,
        ]);
        OrderStatusHistory::factory()->for($drifted)->create();

        try {
            app(TransitionOrderStatus::class)->handle(
                $drifted->order_code,
                $employee,
                OrderStatus::InTransit,
                (string) Str::uuid(),
            );
            $this->fail('History drift must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('target_status', $exception->errors());
        }

        $replayOrder = $this->placedOrder();
        $replayKey = (string) Str::uuid();
        app(TransitionOrderStatus::class)->handle(
            $replayOrder->order_code,
            $employee,
            OrderStatus::AwaitingHandoff,
            $replayKey,
        );
        Order::query()->whereKey($replayOrder->id)->update(['status' => OrderStatus::Placed->value]);
        try {
            app(TransitionOrderStatus::class)->handle(
                $replayOrder->order_code,
                $employee,
                OrderStatus::AwaitingHandoff,
                $replayKey,
            );
            $this->fail('An idempotent replay must not hide Order/history drift.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('target_status', $exception->errors());
        }

        $order = $this->placedOrder();
        Event::listen('eloquent.creating: '.AuditLog::class, fn () => throw new RuntimeException('forced audit failure'));

        try {
            app(TransitionOrderStatus::class)->handle(
                $order->order_code,
                $employee,
                OrderStatus::AwaitingHandoff,
                (string) Str::uuid(),
            );
            $this->fail('The forced audit failure must escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced audit failure', $exception->getMessage());
        }

        $this->assertSame(OrderStatus::Placed, $order->refresh()->status);
        $this->assertSame(1, $order->statusHistories()->count());
        $this->assertSame(0, AuditLog::query()->where('subject_type', Order::class)->where('subject_id', $order->id)->count());
    }

    public function test_transition_does_not_mutate_payment_inventory_coupon_sales_or_membership_data(): void
    {
        $employee = User::factory()->employee()->create();
        $customer = $this->customer();
        $coupon = Coupon::factory()->create(['code' => 'TRANSIT10']);
        $order = Order::factory()->for($customer, 'customer')->create([
            'coupon_id' => $coupon->id,
            'coupon_snapshot_json' => [
                'coupon_id' => $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type->value,
                'scope' => $coupon->scope->value,
                'value' => $coupon->value,
                'eligible_subtotal_vnd' => 200_000,
            ],
            'item_discount_vnd' => 20_000,
            'total_vnd' => 210_000,
        ]);
        OrderStatusHistory::factory()->for($order)->create();
        $product = Product::factory()->create([
            'sellable_quantity' => 7,
            'damaged_quantity' => 2,
            'sold_quantity' => 3,
        ]);
        $item = OrderItem::factory()->for($order)->for($product)->create([
            'discount_vnd' => 20_000,
            'line_total_vnd' => 180_000,
        ]);
        InventoryTransaction::factory()->for($product)->create([
            'sellable_delta' => 7,
            'order_item_id' => null,
        ]);
        $usage = CouponUsage::factory()->consumed($order)->create();
        $before = [
            'payment_status' => $order->payment_status,
            'delivered_at' => $order->delivered_at,
            'membership_spending' => $customer->membership_spending,
            'coupon_usage' => $usage->fresh()->getRawOriginal(),
            'inventory_transaction' => InventoryTransaction::query()->firstOrFail()->getRawOriginal(),
            'product' => $product->fresh()->getRawOriginal(),
            'order_item' => $item->fresh()->getRawOriginal(),
        ];

        app(TransitionOrderStatus::class)->handle(
            $order->order_code,
            $employee,
            OrderStatus::AwaitingHandoff,
            (string) Str::uuid(),
        );
        app(TransitionOrderStatus::class)->handle(
            $order->order_code,
            $employee,
            OrderStatus::InTransit,
            (string) Str::uuid(),
        );

        $order->refresh();
        $customer->refresh();
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame($before['payment_status'], $order->payment_status);
        $this->assertSame($before['delivered_at'], $order->delivered_at);
        $this->assertSame($before['membership_spending'], $customer->membership_spending);
        $this->assertSame($before['coupon_usage'], $usage->fresh()->getRawOriginal());
        $this->assertSame($before['inventory_transaction'], InventoryTransaction::query()->firstOrFail()->getRawOriginal());
        $this->assertSame($before['product'], $product->fresh()->getRawOriginal());
        $this->assertSame($before['order_item'], $item->fresh()->getRawOriginal());
    }

    public function test_http_validation_is_localized_and_conflicting_event_key_is_replaced_for_retry(): void
    {
        $employee = User::factory()->employee()->create();
        $order = $this->placedOrder();

        $this->actingAs($employee)->patch(route('employee.orders.transition', $order->order_code), [
            'target_status' => OrderStatus::AwaitingHandoff->value,
            'event_key' => (string) Str::uuid(),
            'reason' => ['invalid'],
        ])->assertSessionHasErrors([
            'reason' => 'Ghi chú phải là chuỗi ký tự.',
        ]);

        $eventKey = (string) Str::uuid();
        app(TransitionOrderStatus::class)->handle(
            $order->order_code,
            $employee,
            OrderStatus::AwaitingHandoff,
            $eventKey,
        );
        $this->patch(route('employee.orders.transition', $order->order_code), [
            'target_status' => OrderStatus::InTransit->value,
            'event_key' => $eventKey,
        ])->assertSessionHasErrors('event_key');

        $this->get(route('employee.orders.show', $order->order_code))
            ->assertOk()
            ->assertDontSee($eventKey);
    }

    public function test_detail_shows_only_the_supported_next_action_and_customer_view_stays_read_only(): void
    {
        $employee = User::factory()->employee()->create();
        $placed = $this->placedOrder();

        $this->actingAs($employee)->get(route('employee.orders.show', $placed->order_code))
            ->assertOk()
            ->assertSee('Xác nhận chờ chuyển phát')
            ->assertDontSee('Hủy đơn')
            ->assertDontSee('Xác nhận đã giao');

        $inTransit = $this->orderAt(OrderStatus::InTransit);
        $this->get(route('employee.orders.show', $inTransit->order_code))
            ->assertOk()
            ->assertDontSee('Xác nhận chờ chuyển phát')
            ->assertDontSee('Xác nhận đang trung chuyển');

        $customer = $placed->customer;
        $this->actingAs($customer)->get(route('orders.show', $placed->order_code))
            ->assertOk()
            ->assertDontSee('Xác nhận chờ chuyển phát')
            ->assertDontSee('Tiến trình vận chuyển');
    }

    private function placedOrder(?User $customer = null): Order
    {
        return $this->orderAt(OrderStatus::Placed, $customer);
    }

    private function orderAt(OrderStatus $status, ?User $customer = null): Order
    {
        $order = Order::factory()->for($customer ?? $this->customer(), 'customer')->create(['status' => $status]);
        $history = match ($status) {
            OrderStatus::Placed => [OrderStatus::Placed],
            OrderStatus::AwaitingHandoff => [OrderStatus::Placed, OrderStatus::AwaitingHandoff],
            OrderStatus::InTransit => [OrderStatus::Placed, OrderStatus::AwaitingHandoff, OrderStatus::InTransit],
            OrderStatus::Delivered => [OrderStatus::Placed, OrderStatus::AwaitingHandoff, OrderStatus::InTransit, OrderStatus::Delivered],
            OrderStatus::Cancelled => [OrderStatus::Placed, OrderStatus::Cancelled],
        };

        $from = null;
        foreach ($history as $to) {
            OrderStatusHistory::factory()->for($order)->create([
                'from_status' => $from,
                'to_status' => $to,
                'created_at' => now()->addMicrosecond(),
            ]);
            $from = $to;
        }

        return $order;
    }

    private function customer(): User
    {
        return User::factory()->create();
    }
}
