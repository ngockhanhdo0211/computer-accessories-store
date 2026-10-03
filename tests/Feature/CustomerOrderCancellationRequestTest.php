<?php

namespace Tests\Feature;

use App\Actions\ReviewOrderCancellationRequest;
use App\Actions\SubmitOrderCancellationRequest;
use App\Enums\CouponUsageStatus;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderCancellationRequestStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\Refund;
use App\Models\ReturnInspection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class CustomerOrderCancellationRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_submit_only_for_own_placed_order_and_replay_is_idempotent(): void
    {
        [$order, $customer] = $this->codOrder();
        $other = User::factory()->create();
        $key = (string) Str::uuid();

        $this->actingAs($other)->post(route('orders.cancellation-request.store', $order->order_code), [
            'request_key' => $key,
            'reason' => 'Tôi đặt nhầm sản phẩm',
        ])->assertNotFound();

        $this->actingAs($customer)->post(route('orders.cancellation-request.store', $order->order_code), [
            'request_key' => $key,
            'reason' => '  Tôi đặt nhầm sản phẩm  ',
        ])->assertRedirect(route('orders.show', $order->order_code));

        $request = OrderCancellationRequest::query()->sole();
        $this->assertSame('Tôi đặt nhầm sản phẩm', $request->reason);
        $this->assertSame(OrderCancellationRequestStatus::Pending, $request->status);
        $this->assertSame(OrderStatus::Placed, $order->fresh()->status);

        $this->actingAs($customer)->post(route('orders.cancellation-request.store', $order->order_code), [
            'request_key' => $key,
            'reason' => 'Tôi đặt nhầm sản phẩm',
        ])->assertRedirect(route('orders.show', $order->order_code));
        $this->assertDatabaseCount('order_cancellation_requests', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'order.cancellation_requested')->count());

        $this->actingAs($customer)->post(route('orders.cancellation-request.store', $order->order_code), [
            'request_key' => $key,
            'reason' => 'Một nội dung khác',
        ])->assertSessionHasErrors('request_key', null, 'cancellationRequest');
    }

    public function test_rejection_is_terminal_and_has_no_order_inventory_or_payment_side_effect(): void
    {
        [$order, $customer] = $this->codOrder();
        $employee = User::factory()->employee()->create();
        $request = app(SubmitOrderCancellationRequest::class)->handle(
            $customer,
            $order->order_code,
            (string) Str::uuid(),
            'Không còn nhu cầu',
        );
        $eventKey = (string) Str::uuid();

        app(ReviewOrderCancellationRequest::class)->handle($request, $employee, $eventKey, 'rejected', 'Đơn đã được đóng gói');

        $fresh = $request->fresh();
        $this->assertSame(OrderCancellationRequestStatus::Rejected, $fresh->status);
        $this->assertSame($employee->id, $fresh->reviewed_by);
        $this->assertSame(OrderStatus::Placed, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame(1, OrderStatusHistory::query()->where('order_id', $order->id)->count());

        $replay = app(ReviewOrderCancellationRequest::class)->handle($fresh, $employee, $eventKey, 'rejected', 'Đơn đã được đóng gói');
        $this->assertTrue($fresh->is($replay));
        $this->assertSame(1, AuditLog::query()->where('request_id', $eventKey)->count());

        $this->expectException(ValidationException::class);
        app(ReviewOrderCancellationRequest::class)->handle($fresh, $employee, (string) Str::uuid(), 'approved', null);
    }

    public function test_cod_approval_restores_sellable_once_without_return_inspection_or_sold_mutation(): void
    {
        [$order, $customer, $items, $products] = $this->codOrder([2, 3]);
        $admin = User::factory()->admin()->create();
        $request = app(SubmitOrderCancellationRequest::class)->handle($customer, $order->order_code, (string) Str::uuid(), 'Đặt sai cấu hình');
        $eventKey = (string) Str::uuid();
        $before = $products->mapWithKeys(fn (Product $product): array => [$product->id => $product->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity'])]);

        app(ReviewOrderCancellationRequest::class)->handle($request, $admin, $eventKey, 'approved', null);

        $this->assertSame(OrderCancellationRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertDatabaseCount('return_inspections', 0);
        $this->assertDatabaseCount('refunds', 0);
        foreach ($items as $item) {
            $product = $products->firstWhere('id', $item->product_id)->fresh();
            $ledger = InventoryTransaction::query()->where('type', InventoryTransactionType::CancelRestore)
                ->where('order_item_id', $item->id)->sole();
            $this->assertSame($request->id, $ledger->order_cancellation_request_id);
            $this->assertNull($ledger->return_inspection_id);
            $this->assertSame($item->quantity, $ledger->sellable_delta);
            $this->assertSame(0, $ledger->damaged_delta);
            $this->assertSame($before[$product->id]['sellable_quantity'] + $item->quantity, $product->sellable_quantity);
            $this->assertSame($before[$product->id]['damaged_quantity'], $product->damaged_quantity);
            $this->assertSame($before[$product->id]['sold_quantity'], $product->sold_quantity);
        }

        app(ReviewOrderCancellationRequest::class)->handle($request->fresh(), $admin, $eventKey, 'approved', null);
        $this->assertSame($items->count(), InventoryTransaction::query()->where('type', InventoryTransactionType::CancelRestore)->count());
        $this->assertSame(1, OrderStatusHistory::query()->where('event_key', $eventKey)->count());
        $this->assertSame(1, AuditLog::query()->where('request_id', $eventKey)->count());
    }

    public function test_replay_rejects_a_different_actor_and_missing_terminal_evidence(): void
    {
        [$order, $customer] = $this->codOrder();
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $request = app(SubmitOrderCancellationRequest::class)->handle($customer, $order->order_code, (string) Str::uuid(), 'Kiểm tra replay');
        $eventKey = (string) Str::uuid();

        app(ReviewOrderCancellationRequest::class)->handle($request, $admin, $eventKey, 'approved', null);

        try {
            app(ReviewOrderCancellationRequest::class)->handle($request->fresh(), $otherAdmin, $eventKey, 'approved', null);
            $this->fail('A review event must remain bound to its original actor.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('event_key', $exception->errors());
        }

        DB::statement('DROP TRIGGER IF EXISTS audit_logs_delete_guard');
        DB::table('audit_logs')->where('request_id', $eventKey)->delete();

        try {
            app(ReviewOrderCancellationRequest::class)->handle($request->fresh(), $admin, $eventKey, 'approved', null);
            $this->fail('Replay must not silently accept missing audit evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('audit', $exception->errors());
        }
    }

    public function test_cod_approval_releases_consumed_coupon_immediately(): void
    {
        [$codOrder, $codCustomer] = $this->codOrder([1], true);
        $admin = User::factory()->admin()->create();
        $codRequest = app(SubmitOrderCancellationRequest::class)->handle($codCustomer, $codOrder->order_code, (string) Str::uuid(), 'Hủy COD có coupon');
        app(ReviewOrderCancellationRequest::class)->handle($codRequest, $admin, (string) Str::uuid(), 'approved', null);
        $this->assertSame(CouponUsageStatus::Released, $codOrder->couponUsage()->sole()->status);
        $this->assertSame('customer_cancellation_approved', $codOrder->couponUsage()->sole()->release_reason);
    }

    public function test_vnpay_approval_creates_one_pending_full_refund_without_gateway_submission(): void
    {
        $customer = User::factory()->create();
        $coupon = Coupon::factory()->create();
        $attempt = PaymentAttempt::factory()->create(['user_id' => $customer->id, 'coupon_id' => $coupon->id]);
        $attempt->finalizeCallback([
            'status' => PaymentStatus::Paid,
            'gateway_transaction_id' => 'TX-'.Str::upper(Str::random(20)),
            'gateway_result_code' => '00',
            'gateway_transaction_status' => '00',
            'gateway_paid_at' => now(),
            'callback_fingerprint' => hash('sha256', (string) Str::uuid()),
            'verified_at' => now(),
        ]);
        $couponSnapshot = [
            'coupon_id' => $coupon->id,
            'code' => $coupon->code,
            'type' => $coupon->type->value,
            'scope' => $coupon->scope->value,
            'value' => $coupon->value,
            'eligible_subtotal_vnd' => $attempt->pricing_snapshot_json['cart_subtotal_vnd'],
        ];
        $order = Order::factory()->forVerifiedAttempt($attempt->fresh())->create(['coupon_snapshot_json' => $couponSnapshot]);
        $product = Product::factory()->create(['sellable_quantity' => 6, 'damaged_quantity' => 1, 'sold_quantity' => 4]);
        $item = OrderItem::factory()->for($order)->for($product)->create([
            'quantity' => 1,
            'unit_price_vnd' => $order->items_subtotal_vnd,
            'line_subtotal_vnd' => $order->items_subtotal_vnd,
            'discount_vnd' => $order->item_discount_vnd,
            'line_total_vnd' => $order->items_subtotal_vnd - $order->item_discount_vnd,
        ]);
        $this->initialHistory($order);
        (new CouponUsage)->forceFill([
            'coupon_id' => $coupon->id,
            'customer_id' => $customer->id,
            'payment_attempt_id' => $attempt->id,
            'order_id' => $order->id,
            'status' => CouponUsageStatus::Consumed,
            'reserved_at' => now()->subMinutes(2),
            'expires_at' => now()->addMinutes(13),
            'consumed_at' => now()->subMinute(),
            'released_at' => null,
            'release_reason' => null,
            'late_callback_exception' => false,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ])->save();
        $request = app(SubmitOrderCancellationRequest::class)->handle($customer, $order->order_code, (string) Str::uuid(), 'Muốn đổi phương thức mua');
        $admin = User::factory()->admin()->create();

        DB::unprepared("CREATE TRIGGER customer_cancel_refund_failure BEFORE INSERT ON refunds WHEN NEW.reason = 'customer_cancellation' BEGIN SELECT RAISE(ABORT, 'forced refund failure'); END");
        try {
            app(ReviewOrderCancellationRequest::class)->handle($request, $admin, (string) Str::uuid(), 'approved', 'Rollback refund');
            $this->fail('Refund failure must roll back the whole VNPay approval.');
        } catch (QueryException) {
            $this->assertSame(OrderCancellationRequestStatus::Pending, $request->fresh()->status);
            $this->assertSame(OrderStatus::Placed, $order->fresh()->status);
            $this->assertSame(6, $product->fresh()->sellable_quantity);
            $this->assertSame(CouponUsageStatus::Consumed, $order->couponUsage()->sole()->status);
            $this->assertDatabaseCount('refunds', 0);
            $this->assertDatabaseMissing('inventory_transactions', ['order_item_id' => $item->id, 'type' => InventoryTransactionType::CancelRestore->value]);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS customer_cancel_refund_failure');
        }

        app(ReviewOrderCancellationRequest::class)->handle($request, $admin, (string) Str::uuid(), 'approved', 'Tạo refund chờ xử lý');

        $refund = Refund::query()->sole();
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertSame(RefundReason::CustomerCancellation, $refund->reason);
        $this->assertSame($attempt->id, $refund->payment_attempt_id);
        $this->assertSame($order->id, $refund->order_id);
        $this->assertSame($attempt->amount_vnd, $refund->amount_vnd);
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(CouponUsageStatus::Consumed, $order->couponUsage()->sole()->status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(7, $product->fresh()->sellable_quantity);
        $this->assertSame(4, $product->fresh()->sold_quantity);
        $this->assertDatabaseHas('inventory_transactions', [
            'order_item_id' => $item->id,
            'order_cancellation_request_id' => $request->id,
            'return_inspection_id' => null,
        ]);
        $this->assertDatabaseCount('refund_gateway_attempts', 0);
    }

    public function test_database_guards_request_lifecycle_and_both_cancel_restore_sources(): void
    {
        [$order, $customer, $items, $products] = $this->codOrder();
        $request = app(SubmitOrderCancellationRequest::class)->handle($customer, $order->order_code, (string) Str::uuid(), 'Kiểm tra guard');
        $admin = User::factory()->admin()->create();

        try {
            InventoryTransaction::query()->forceCreate([
                'product_id' => $products->first()->id,
                'type' => InventoryTransactionType::CancelRestore,
                'sellable_delta' => $items->first()->quantity,
                'damaged_delta' => 0,
                'source_key' => 'pending-request-guard',
                'order_item_id' => $items->first()->id,
                'return_inspection_id' => null,
                'order_cancellation_request_id' => $request->id,
                'actor_id' => $admin->id,
                'reason' => 'Không hợp lệ',
                'created_at' => now(),
            ]);
            $this->fail('Pending cancellation request must not authorize a cancel_restore ledger.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('inventory_transactions', ['source_key' => 'pending-request-guard']);
        }

        try {
            $request->delete();
            $this->fail('Cancellation request evidence must be immutable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('order_cancellation_requests', ['id' => $request->id]);
        }
        $this->assertSame(0, ReturnInspection::query()->count());
    }

    public function test_approval_rolls_back_request_order_projection_ledger_history_and_audit_at_failure_boundaries(): void
    {
        foreach (['request', 'product', 'ledger', 'order', 'coupon', 'history', 'audit'] as $failure) {
            [$order, $customer, $items, $products] = $this->codOrder([1, 1], $failure === 'coupon');
            $admin = User::factory()->admin()->create();
            $request = app(SubmitOrderCancellationRequest::class)->handle($customer, $order->order_code, (string) Str::uuid(), 'Rollback '.$failure);
            $before = $products->mapWithKeys(fn (Product $product): array => [$product->id => $product->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity'])]);
            $historyCount = OrderStatusHistory::query()->where('order_id', $order->id)->count();
            $auditCount = AuditLog::query()->count();
            $trigger = 'customer_cancel_failure_'.$failure;
            $statement = match ($failure) {
                'request' => "CREATE TRIGGER {$trigger} BEFORE UPDATE ON order_cancellation_requests WHEN NEW.status = 'approved' BEGIN SELECT RAISE(ABORT, 'forced request failure'); END",
                'product' => "CREATE TRIGGER {$trigger} BEFORE UPDATE OF sellable_quantity ON products WHEN OLD.id = {$products->last()->id} BEGIN SELECT RAISE(ABORT, 'forced product failure'); END",
                'ledger' => "CREATE TRIGGER {$trigger} BEFORE INSERT ON inventory_transactions WHEN NEW.order_item_id = {$items->last()->id} AND NEW.type = 'cancel_restore' BEGIN SELECT RAISE(ABORT, 'forced ledger failure'); END",
                'order' => "CREATE TRIGGER {$trigger} BEFORE UPDATE OF status ON orders WHEN OLD.id = {$order->id} AND NEW.status = 'da_huy' BEGIN SELECT RAISE(ABORT, 'forced order failure'); END",
                'coupon' => "CREATE TRIGGER {$trigger} BEFORE UPDATE OF status ON coupon_usages WHEN NEW.order_id = {$order->id} AND NEW.status = 'released' BEGIN SELECT RAISE(ABORT, 'forced coupon failure'); END",
                'history' => "CREATE TRIGGER {$trigger} BEFORE INSERT ON order_status_histories WHEN NEW.order_id = {$order->id} AND NEW.to_status = 'da_huy' BEGIN SELECT RAISE(ABORT, 'forced history failure'); END",
                'audit' => "CREATE TRIGGER {$trigger} BEFORE INSERT ON audit_logs WHEN NEW.action = 'order.cancellation_approved' BEGIN SELECT RAISE(ABORT, 'forced audit failure'); END",
            };
            DB::unprepared($statement);

            try {
                app(ReviewOrderCancellationRequest::class)->handle($request, $admin, (string) Str::uuid(), 'approved', null);
                $this->fail('Injected failure must roll back approval.');
            } catch (QueryException) {
                $this->assertSame(OrderCancellationRequestStatus::Pending, $request->fresh()->status);
                $this->assertSame(OrderStatus::Placed, $order->fresh()->status);
                $this->assertSame($historyCount, OrderStatusHistory::query()->where('order_id', $order->id)->count());
                $this->assertSame($auditCount, AuditLog::query()->count());
                $this->assertSame(0, InventoryTransaction::query()->whereIn('order_item_id', $items->pluck('id'))->count());
                foreach ($products as $product) {
                    $this->assertSame($before[$product->id], $product->fresh()->only(array_keys($before[$product->id])));
                }
                if ($failure === 'coupon') {
                    $this->assertSame(CouponUsageStatus::Consumed, $order->couponUsage()->sole()->status);
                }
            } finally {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
        }
    }

    public function test_migration_round_trips_when_empty_and_refuses_to_remove_request_evidence(): void
    {
        $migration = require database_path('migrations/2026_10_05_000000_create_order_cancellation_requests.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('order_cancellation_requests'));
        $this->assertFalse(Schema::hasColumn('inventory_transactions', 'order_cancellation_request_id'));
        $legacyRefundGuard = DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'refunds_insert_check')->value('sql');
        $this->assertStringNotContainsString('customer_cancellation', (string) $legacyRefundGuard);
        $migration->up();
        $upgradedRefundGuard = DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'refunds_insert_check')->value('sql');
        $this->assertStringContainsString('customer_cancellation', (string) $upgradedRefundGuard);

        [$order, $customer] = $this->codOrder();
        $request = app(SubmitOrderCancellationRequest::class)->handle($customer, $order->order_code, (string) Str::uuid(), 'Giữ bằng chứng');
        try {
            $migration->down();
            $this->fail('Rollback must preserve cancellation request evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('evidence', $exception->getMessage());
        }
        $this->assertDatabaseHas('order_cancellation_requests', ['id' => $request->id]);
    }

    public function test_routes_use_expected_methods_and_role_middleware(): void
    {
        $routes = app('router')->getRoutes();
        $this->assertSame(['POST'], $routes->getByName('orders.cancellation-request.store')->methods());
        foreach (['admin', 'employee'] as $prefix) {
            $this->assertSame(['GET', 'HEAD'], $routes->getByName($prefix.'.order-cancellation-requests.index')->methods());
            $this->assertSame(['GET', 'HEAD'], $routes->getByName($prefix.'.order-cancellation-requests.show')->methods());
            $this->assertSame(['PATCH'], $routes->getByName($prefix.'.order-cancellation-requests.approve')->methods());
            $this->assertSame(['PATCH'], $routes->getByName($prefix.'.order-cancellation-requests.reject')->methods());
        }
    }

    public function test_customer_and_staff_views_show_only_the_actions_allowed_for_their_roles(): void
    {
        [$order, $customer] = $this->codOrder();
        $request = app(SubmitOrderCancellationRequest::class)->handle(
            $customer,
            $order->order_code,
            (string) Str::uuid(),
            'Cần đổi sang sản phẩm khác',
        );
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($customer)->get(route('orders.show', $order->order_code))
            ->assertOk()->assertSee('Cần đổi sang sản phẩm khác')->assertSee('Chờ xử lý')
            ->assertDontSee('Chấp thuận hủy');
        $this->actingAs($employee)->get(route('employee.order-cancellation-requests.index'))
            ->assertOk()->assertSee($order->order_code)->assertSee('Cần đổi sang sản phẩm khác');
        $this->actingAs($employee)->get(route('employee.order-cancellation-requests.show', $request))
            ->assertOk()->assertSee('Chấp thuận hủy')->assertSee('Từ chối');
        $this->actingAs($admin)->get(route('admin.order-cancellation-requests.show', $request))
            ->assertOk()->assertSee($order->order_code);

        $this->actingAs($customer)->get(route('employee.order-cancellation-requests.show', $request))->assertForbidden();
        $this->actingAs($employee)->post(route('orders.cancellation-request.store', $order->order_code), [
            'request_key' => (string) Str::uuid(),
            'reason' => 'Không được phép',
        ])->assertForbidden();
    }

    public function test_review_forms_keep_validation_errors_in_their_own_bag(): void
    {
        [$order, $customer] = $this->codOrder();
        $request = app(SubmitOrderCancellationRequest::class)->handle($customer, $order->order_code, (string) Str::uuid(), 'Tách lỗi form');
        $employee = User::factory()->employee()->create();

        $rejectResponse = $this->actingAs($employee)->patch(route('employee.order-cancellation-requests.reject', $request), [
            'event_key' => ' '.Str::uuid().' ',
            'note' => '   ',
        ]);
        $rejectResponse->assertSessionHasErrors('note', null, 'rejectCancellation')
            ->assertSessionHas('errors', fn ($errors): bool => ! $errors->hasBag('approveCancellation'));
        $this->app['session']->forget('errors');

        $approveResponse = $this->actingAs($employee)->patch(route('employee.order-cancellation-requests.approve', $request), [
            'event_key' => 'not-a-uuid',
            'note' => null,
        ]);
        $approveResponse->assertSessionHasErrors('event_key', null, 'approveCancellation')
            ->assertSessionHas('errors', fn ($errors): bool => ! $errors->hasBag('rejectCancellation'));
    }

    /** @return array{Order, User, Collection<int, OrderItem>, Collection<int, Product>} */
    private function codOrder(array $quantities = [2], bool $withCoupon = false): array
    {
        $customer = User::factory()->create();
        $subtotal = array_sum($quantities) * 100_000;
        $coupon = $withCoupon ? Coupon::factory()->create() : null;
        $order = Order::factory()->for($customer, 'customer')->create([
            'coupon_id' => $coupon?->id,
            'coupon_snapshot_json' => $coupon === null ? null : [
                'coupon_id' => $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type->value,
                'scope' => $coupon->scope->value,
                'value' => $coupon->value,
                'eligible_subtotal_vnd' => $subtotal,
            ],
            'items_subtotal_vnd' => $subtotal,
            'item_discount_vnd' => 0,
            'total_vnd' => $subtotal + 30_000,
        ]);
        $items = collect();
        $products = collect();
        foreach ($quantities as $index => $quantity) {
            $product = Product::factory()->create([
                'sku' => 'CUSTOMER-CANCEL-'.$order->id.'-'.$index,
                'sellable_quantity' => 5,
                'damaged_quantity' => 1,
                'sold_quantity' => 4,
            ]);
            $items->push(OrderItem::factory()->for($order)->for($product)->create([
                'quantity' => $quantity,
                'unit_price_vnd' => 100_000,
                'line_subtotal_vnd' => $quantity * 100_000,
                'discount_vnd' => 0,
                'line_total_vnd' => $quantity * 100_000,
            ]));
            $products->push($product);
        }
        $this->initialHistory($order);
        if ($coupon !== null) {
            (new CouponUsage)->forceFill([
                'coupon_id' => $coupon->id,
                'customer_id' => $customer->id,
                'payment_attempt_id' => null,
                'order_id' => $order->id,
                'status' => CouponUsageStatus::Consumed,
                'reserved_at' => null,
                'expires_at' => null,
                'consumed_at' => now(),
                'released_at' => null,
                'release_reason' => null,
                'late_callback_exception' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ])->save();
        }

        return [$order, $customer, $items, $products];
    }

    private function initialHistory(Order $order): void
    {
        (new OrderStatusHistory)->forceFill([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => OrderStatus::Placed,
            'actor_id' => null,
            'reason' => null,
            'event_key' => (string) Str::uuid(),
            'created_at' => now(),
        ])->save();
    }
}
