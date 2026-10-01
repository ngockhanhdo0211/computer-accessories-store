<?php

namespace Tests\Feature;

use App\Actions\ApplyDeliveredOrderMembershipSpending;
use App\Actions\CancelCodOrder;
use App\Actions\CompleteReturnInspection;
use App\Actions\DeliverCodOrder;
use App\Actions\ReceiveReturnInspection;
use App\Enums\CouponUsageStatus;
use App\Enums\InventoryTransactionType;
use App\Enums\MembershipLevel;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class CodOrderTerminalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_and_admin_cancel_eligible_cod_orders_with_one_combined_ledger_per_item(): void
    {
        foreach ([
            [OrderStatus::Placed, User::factory()->employee()],
            [OrderStatus::Placed, User::factory()->admin()],
            [OrderStatus::AwaitingHandoff, User::factory()->employee()],
            [OrderStatus::AwaitingHandoff, User::factory()->admin()],
            [OrderStatus::InTransit, User::factory()->admin()],
        ] as [$status, $actorFactory]) {
            $actor = $actorFactory->create();
            [$order, $items, $products] = $this->order($status, [2, 3]);
            $this->completeInspections($order, $items, $actor, [[1, 1], [0, 3]]);
            $before = $products->map(fn (Product $product) => $product->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity']));
            $key = (string) Str::uuid();

            app(CancelCodOrder::class)->handle($order->order_code, $actor, $key, 'Khách từ chối nhận hàng');

            $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
            $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
            $ledgers = InventoryTransaction::query()->where('type', InventoryTransactionType::CancelRestore)
                ->whereIn('order_item_id', $items->pluck('id'))->orderBy('order_item_id')->get();
            $this->assertCount(2, $ledgers);
            foreach ($items->values() as $index => $item) {
                $inspection = $item->returnInspection()->firstOrFail();
                $ledger = $ledgers->firstWhere('order_item_id', $item->id);
                $product = $products->firstWhere('id', $item->product_id)->fresh();
                $this->assertSame($inspection->id, $ledger->return_inspection_id);
                $this->assertSame($inspection->sellable_quantity, $ledger->sellable_delta);
                $this->assertSame($inspection->damaged_quantity, $ledger->damaged_delta);
                $this->assertSame($before[$index]['sellable_quantity'] + $inspection->sellable_quantity, $product->sellable_quantity);
                $this->assertSame($before[$index]['damaged_quantity'] + $inspection->damaged_quantity, $product->damaged_quantity);
                $this->assertSame($before[$index]['sold_quantity'], $product->sold_quantity);
            }
            $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'event_key' => $key, 'to_status' => OrderStatus::Cancelled->value]);
            $this->assertDatabaseHas('audit_logs', ['request_id' => $key, 'action' => 'order.cod.cancelled']);
        }
    }

    public function test_cancellation_releases_consumed_cod_coupon_once_and_replay_has_no_side_effect(): void
    {
        $admin = User::factory()->admin()->create();
        [$order, $items, $products] = $this->order(OrderStatus::InTransit, [2], true);
        $this->completeInspections($order, $items, $admin, [[1, 1]]);
        $key = (string) Str::uuid();

        $first = app(CancelCodOrder::class)->handle($order->order_code, $admin, $key, 'Hàng đã quay lại kho');
        $projection = $products->first()->fresh()->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity']);
        $releasedAt = $order->couponUsage()->firstOrFail()->released_at;
        $second = app(CancelCodOrder::class)->handle($order->order_code, $admin, $key, 'Hàng đã quay lại kho');

        $this->assertTrue($first->is($second));
        $this->assertSame($projection, $products->first()->fresh()->only(array_keys($projection)));
        $this->assertTrue($releasedAt->equalTo($order->couponUsage()->firstOrFail()->released_at));
        $this->assertSame(CouponUsageStatus::Released, $order->couponUsage()->firstOrFail()->status);
        $this->assertSame(1, InventoryTransaction::query()->where('type', InventoryTransactionType::CancelRestore)->where('order_item_id', $items->first()->id)->count());
        $this->assertSame(1, OrderStatusHistory::query()->where('event_key', $key)->count());
        $this->assertSame(1, AuditLog::query()->where('request_id', $key)->count());
    }

    public function test_cancellation_rejects_ineligible_actor_status_payment_method_and_inspection_state(): void
    {
        $employee = User::factory()->employee()->create();
        [$inTransit, $items] = $this->order(OrderStatus::InTransit, [2]);
        $this->completeInspections($inTransit, $items, User::factory()->admin()->create(), [[2, 0]]);
        $this->assertRejected(fn () => app(CancelCodOrder::class)->handle($inTransit->order_code, $employee, (string) Str::uuid(), 'Không đủ quyền'));

        foreach ([User::factory()->create(), User::factory()->employee()->locked()->create(), User::factory()->admin()->inactive()->create()] as $actor) {
            [$order] = $this->order(OrderStatus::Placed, [1]);
            $this->assertRejected(fn () => app(CancelCodOrder::class)->handle($order->order_code, $actor, (string) Str::uuid(), 'Không hợp lệ'));
        }

        [$missing] = $this->order(OrderStatus::Placed, [1]);
        $this->assertRejected(fn () => app(CancelCodOrder::class)->handle($missing->order_code, $employee, (string) Str::uuid(), 'Thiếu kiểm tra'));

        [$pending, $pendingItems] = $this->order(OrderStatus::Placed, [1]);
        app(ReceiveReturnInspection::class)->handle($pending->order_code, $pendingItems->first()->id, $employee, now());
        $this->assertRejected(fn () => app(CancelCodOrder::class)->handle($pending->order_code, $employee, (string) Str::uuid(), 'Chưa phân loại'));

        [$paid, $paidItems] = $this->order(OrderStatus::Placed, [1]);
        $this->completeInspections($paid, $paidItems, $employee, [[1, 0]]);
        $paid->forceFill(['payment_status' => PaymentStatus::Paid])->save();
        $this->assertRejected(fn () => app(CancelCodOrder::class)->handle($paid->order_code, $employee, (string) Str::uuid(), 'Đã thanh toán'));

        [$wrongTimestamp, $timestampItems] = $this->order(OrderStatus::Placed, [1]);
        $this->completeInspections($wrongTimestamp, $timestampItems, $employee, [[1, 0]]);
        $wrongTimestamp->forceFill(['delivered_at' => now()])->save();
        $this->assertRejected(fn () => app(CancelCodOrder::class)->handle($wrongTimestamp->order_code, $employee, (string) Str::uuid(), 'Timestamp sai'));

        [$delivered, $deliveredItems] = $this->order(OrderStatus::Delivered, [1]);
        $this->assertRejected(fn () => app(CancelCodOrder::class)->handle($delivered->order_code, User::factory()->admin()->create(), (string) Str::uuid(), 'Terminal'));
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertNotEmpty($deliveredItems);
    }

    public function test_cancellation_rolls_back_projection_ledger_coupon_history_and_order_when_audit_fails(): void
    {
        $admin = User::factory()->admin()->create();
        [$order, $items, $products] = $this->order(OrderStatus::InTransit, [2], true);
        $this->completeInspections($order, $items, $admin, [[1, 1]]);
        $before = $products->first()->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity']);
        DB::unprepared("CREATE TRIGGER terminal_qa_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.action = 'order.cod.cancelled' BEGIN SELECT RAISE(ABORT, 'forced audit failure'); END");

        try {
            app(CancelCodOrder::class)->handle($order->order_code, $admin, (string) Str::uuid(), 'Rollback toàn bộ');
            $this->fail('Audit failure must roll back cancellation.');
        } catch (QueryException) {
            $this->assertSame(OrderStatus::InTransit, $order->fresh()->status);
            $this->assertSame($before, $products->first()->fresh()->only(array_keys($before)));
            $this->assertSame(CouponUsageStatus::Consumed, $order->couponUsage()->firstOrFail()->status);
            $this->assertDatabaseMissing('inventory_transactions', ['type' => InventoryTransactionType::CancelRestore->value]);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS terminal_qa_audit_failure');
        }
    }

    public function test_cancellation_rolls_back_at_each_multistep_failure_boundary(): void
    {
        foreach (['second_product', 'ledger', 'coupon', 'history'] as $failure) {
            $admin = User::factory()->admin()->create();
            [$order, $items, $products] = $this->order(OrderStatus::InTransit, [1, 1], true);
            $this->completeInspections($order, $items, $admin, [[1, 0], [0, 1]]);
            $before = $products->mapWithKeys(fn (Product $product): array => [
                $product->id => $product->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity']),
            ]);
            $historyCount = $order->statusHistories()->count();
            $auditCount = AuditLog::query()->count();
            $trigger = 'terminal_qa_cancel_'.$failure;
            $statement = match ($failure) {
                'second_product' => "CREATE TRIGGER {$trigger} BEFORE UPDATE OF sellable_quantity, damaged_quantity ON products WHEN OLD.id = {$products->last()->id} BEGIN SELECT RAISE(ABORT, 'forced product failure'); END",
                'ledger' => "CREATE TRIGGER {$trigger} BEFORE INSERT ON inventory_transactions WHEN NEW.type = 'cancel_restore' BEGIN SELECT RAISE(ABORT, 'forced ledger failure'); END",
                'coupon' => "CREATE TRIGGER {$trigger} BEFORE UPDATE ON coupon_usages WHEN NEW.status = 'released' BEGIN SELECT RAISE(ABORT, 'forced coupon failure'); END",
                'history' => "CREATE TRIGGER {$trigger} BEFORE INSERT ON order_status_histories WHEN NEW.to_status = 'da_huy' BEGIN SELECT RAISE(ABORT, 'forced history failure'); END",
            };
            DB::unprepared($statement);

            try {
                app(CancelCodOrder::class)->handle($order->order_code, $admin, (string) Str::uuid(), 'Rollback boundary');
                $this->fail('Injected cancellation failure must roll back every write.');
            } catch (QueryException) {
                $this->assertSame(OrderStatus::InTransit, $order->fresh()->status);
                $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
                $this->assertSame(CouponUsageStatus::Consumed, $order->couponUsage()->firstOrFail()->status);
                $this->assertSame($historyCount, $order->statusHistories()->count());
                $this->assertSame($auditCount, AuditLog::query()->count());
                $this->assertSame(0, InventoryTransaction::query()->where('type', InventoryTransactionType::CancelRestore)->whereIn('order_item_id', $items->pluck('id'))->count());
                foreach ($products as $product) {
                    $this->assertSame($before[$product->id], $product->fresh()->only(array_keys($before[$product->id])));
                }
            } finally {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
        }
    }

    public function test_delivery_updates_payment_sold_and_membership_without_inventory_or_coupon_mutation(): void
    {
        $employee = User::factory()->employee()->create();
        [$order, $items, $products] = $this->order(OrderStatus::InTransit, [2], true, 2_500_000);
        $inventoryCount = InventoryTransaction::query()->count();
        $usageBefore = $order->couponUsage()->firstOrFail()->getAttributes();
        $sellable = $products->first()->sellable_quantity;
        $damaged = $products->first()->damaged_quantity;
        $sold = $products->first()->sold_quantity;
        $key = (string) Str::uuid();

        app(DeliverCodOrder::class)->handle($order->order_code, $employee, $key, 'Đã nhận đủ hàng');

        $fresh = $order->fresh();
        $product = $products->first()->fresh();
        $this->assertSame(OrderStatus::Delivered, $fresh->status);
        $this->assertSame(PaymentStatus::Paid, $fresh->payment_status);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertSame($sold + $items->first()->quantity, $product->sold_quantity);
        $this->assertSame($sellable, $product->sellable_quantity);
        $this->assertSame($damaged, $product->damaged_quantity);
        $this->assertSame($inventoryCount, InventoryTransaction::query()->count());
        $this->assertSame($usageBefore, $order->couponUsage()->firstOrFail()->getAttributes());
        $customer = $order->customer()->firstOrFail();
        $this->assertSame(5_000_000, $customer->membership_spending);
        $this->assertSame(MembershipLevel::Bac, $customer->current_tier);
        $this->assertDatabaseHas('membership_histories', ['user_id' => $customer->id, 'spending_vnd' => 5_000_000]);
        $this->assertDatabaseHas('audit_logs', ['request_id' => $key, 'action' => 'order.cod.delivered']);
    }

    public function test_admin_and_employee_can_deliver_only_in_transit_cod_and_replay_is_idempotent(): void
    {
        foreach ([User::factory()->employee()->create(), User::factory()->admin()->create()] as $actor) {
            [$order, $items, $products] = $this->order(OrderStatus::InTransit, [2]);
            $key = (string) Str::uuid();
            app(DeliverCodOrder::class)->handle($order->order_code, $actor, $key);
            $sold = $products->first()->fresh()->sold_quantity;
            $spending = $order->customer()->firstOrFail()->membership_spending;
            app(DeliverCodOrder::class)->handle($order->order_code, $actor, $key);
            $this->assertSame($sold, $products->first()->fresh()->sold_quantity);
            $this->assertSame($spending, $order->customer()->firstOrFail()->membership_spending);
            $this->assertSame(1, OrderStatusHistory::query()->where('event_key', $key)->count());
            $this->assertSame(1, AuditLog::query()->where('request_id', $key)->count());
            $this->assertSame($items->first()->quantity + 4, $sold);
        }

        foreach ([OrderStatus::Placed, OrderStatus::AwaitingHandoff, OrderStatus::Delivered, OrderStatus::Cancelled] as $status) {
            [$order] = $this->order($status, [1]);
            $this->assertRejected(fn () => app(DeliverCodOrder::class)->handle($order->order_code, User::factory()->admin()->create(), (string) Str::uuid()));
        }

        foreach ([User::factory()->create(), User::factory()->employee()->locked()->create(), User::factory()->admin()->inactive()->create()] as $actor) {
            [$order] = $this->order(OrderStatus::InTransit, [1]);
            $this->assertRejected(fn () => app(DeliverCodOrder::class)->handle($order->order_code, $actor, (string) Str::uuid()));
        }
    }

    public function test_delivery_failure_in_membership_rolls_back_order_history_sold_and_audit(): void
    {
        $admin = User::factory()->admin()->create();
        [$order, $items, $products] = $this->order(OrderStatus::InTransit, [2]);
        $sold = $products->first()->sold_quantity;
        $historyCount = $order->statusHistories()->count();
        $this->mock(ApplyDeliveredOrderMembershipSpending::class, function ($mock): void {
            $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('forced membership failure'));
        });

        try {
            app(DeliverCodOrder::class)->handle($order->order_code, $admin, (string) Str::uuid());
            $this->fail('Membership failure must roll back delivery.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced membership failure', $exception->getMessage());
        }

        $this->assertSame(OrderStatus::InTransit, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->delivered_at);
        $this->assertSame($sold, $products->first()->fresh()->sold_quantity);
        $this->assertSame($historyCount, $order->statusHistories()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'order.cod.delivered']);
        $this->assertNotEmpty($items);
    }

    public function test_delivery_rolls_back_second_product_history_and_audit_failures(): void
    {
        foreach (['second_product', 'history', 'audit'] as $failure) {
            $admin = User::factory()->admin()->create();
            [$order, $items, $products] = $this->order(OrderStatus::InTransit, [1, 1]);
            $beforeProducts = $products->mapWithKeys(fn (Product $product): array => [
                $product->id => $product->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity']),
            ]);
            $customer = $order->customer()->firstOrFail();
            $membershipBefore = $customer->only(['membership_spending', 'current_tier']);
            $historyCount = $order->statusHistories()->count();
            $auditCount = AuditLog::query()->count();
            $trigger = 'terminal_qa_deliver_'.$failure;
            $statement = match ($failure) {
                'second_product' => "CREATE TRIGGER {$trigger} BEFORE UPDATE OF sold_quantity ON products WHEN OLD.id = {$products->last()->id} BEGIN SELECT RAISE(ABORT, 'forced sold failure'); END",
                'history' => "CREATE TRIGGER {$trigger} BEFORE INSERT ON order_status_histories WHEN NEW.to_status = 'da_giao' BEGIN SELECT RAISE(ABORT, 'forced history failure'); END",
                'audit' => "CREATE TRIGGER {$trigger} BEFORE INSERT ON audit_logs WHEN NEW.action = 'order.cod.delivered' BEGIN SELECT RAISE(ABORT, 'forced audit failure'); END",
            };
            DB::unprepared($statement);

            try {
                app(DeliverCodOrder::class)->handle($order->order_code, $admin, (string) Str::uuid());
                $this->fail('Injected delivery failure must roll back every write.');
            } catch (QueryException) {
                $this->assertSame(OrderStatus::InTransit, $order->fresh()->status);
                $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
                $this->assertNull($order->fresh()->delivered_at);
                $this->assertSame($historyCount, $order->statusHistories()->count());
                $this->assertSame($auditCount, AuditLog::query()->count());
                $this->assertSame($membershipBefore, $customer->fresh()->only(array_keys($membershipBefore)));
                $this->assertDatabaseCount('membership_histories', 0);
                foreach ($products as $product) {
                    $this->assertSame($beforeProducts[$product->id], $product->fresh()->only(array_keys($beforeProducts[$product->id])));
                }
                $this->assertSame(0, InventoryTransaction::query()->whereIn('order_item_id', $items->pluck('id'))->count());
            } finally {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
        }
    }

    public function test_event_key_payload_conflicts_and_cancel_deliver_race_contract_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        [$order, $items] = $this->order(OrderStatus::InTransit, [1]);
        $this->completeInspections($order, $items, $admin, [[1, 0]]);
        $key = (string) Str::uuid();
        app(CancelCodOrder::class)->handle($order->order_code, $admin, $key, 'Payload A');

        $this->assertRejected(fn () => app(CancelCodOrder::class)->handle($order->order_code, $admin, $key, 'Payload B'));
        $this->assertRejected(fn () => app(DeliverCodOrder::class)->handle($order->order_code, $admin, $key, 'Payload A'));
    }

    public function test_event_keys_are_global_and_replay_rejects_missing_audit_evidence(): void
    {
        $admin = User::factory()->admin()->create();
        [$first] = $this->order(OrderStatus::InTransit, [1]);
        [$second] = $this->order(OrderStatus::InTransit, [1]);
        $key = (string) Str::uuid();

        app(DeliverCodOrder::class)->handle($first->order_code, $admin, $key, 'Global key');
        $this->assertRejected(fn () => app(DeliverCodOrder::class)->handle($second->order_code, $admin, $key, 'Global key'));
        $this->assertSame(OrderStatus::InTransit, $second->fresh()->status);

        [$drifted] = $this->order(OrderStatus::Delivered, [1]);
        $history = $drifted->statusHistories()->latest('created_at')->latest('id')->firstOrFail();
        $this->assertRejected(fn () => app(DeliverCodOrder::class)->handle(
            $drifted->order_code,
            $admin,
            $history->event_key,
            null,
        ));
        $this->assertDatabaseMissing('audit_logs', ['request_id' => $history->event_key]);
    }

    public function test_database_boundary_allows_other_types_but_only_one_cancel_restore_per_order_item(): void
    {
        [$order, $items, $products] = $this->order(OrderStatus::Placed, [1]);
        $this->completeInspections($order, $items, User::factory()->admin()->create(), [[1, 0]]);
        $item = $items->first();
        $product = $products->first();
        $inspection = $item->returnInspection()->firstOrFail();
        $base = [
            'product_id' => $product->id, 'sellable_delta' => -1, 'damaged_delta' => 0,
            'adjustment_request_id' => null, 'order_item_id' => $item->id,
            'return_inspection_id' => null, 'actor_id' => null, 'reason' => null, 'created_at' => now(),
        ];
        DB::table('inventory_transactions')->insert([...$base, 'type' => 'sale', 'source_key' => 'sale-boundary']);
        DB::table('inventory_transactions')->insert([
            ...$base, 'type' => 'cancel_restore', 'sellable_delta' => 1,
            'return_inspection_id' => $inspection->id, 'source_key' => 'restore-one',
        ]);
        try {
            DB::table('inventory_transactions')->insert([
                ...$base, 'type' => 'cancel_restore', 'sellable_delta' => 1,
                'return_inspection_id' => $inspection->id, 'source_key' => 'restore-two',
            ]);
            $this->fail('A second cancel_restore for one Order Item must be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseCount('inventory_transactions', 2);
        }
        $this->assertNotNull($order);
    }

    public function test_database_guards_cancel_restore_shape_coupon_release_and_ledger_immutability(): void
    {
        $admin = User::factory()->admin()->create();
        [$order, $items, $products] = $this->order(OrderStatus::Placed, [2], true);
        $this->completeInspections($order, $items, $admin, [[1, 1]]);
        $item = $items->first();
        $product = $products->first();
        $inspection = $item->returnInspection()->firstOrFail();
        $base = [
            'product_id' => $product->id, 'type' => 'cancel_restore',
            'sellable_delta' => 1, 'damaged_delta' => 1, 'adjustment_request_id' => null,
            'order_item_id' => $item->id, 'return_inspection_id' => $inspection->id,
            'actor_id' => $admin->id, 'reason' => 'Direct SQL guard', 'created_at' => now(),
        ];

        foreach ([
            ['return_inspection_id' => null],
            ['sellable_delta' => -1, 'damaged_delta' => 3],
            ['sellable_delta' => 2, 'damaged_delta' => 0],
            ['product_id' => Product::factory()->create()->id],
        ] as $index => $invalid) {
            try {
                DB::table('inventory_transactions')->insert([...$base, ...$invalid, 'source_key' => 'invalid-restore-'.$index]);
                $this->fail('Database accepted an invalid cancel_restore shape.');
            } catch (QueryException) {
                $this->assertDatabaseMissing('inventory_transactions', ['source_key' => 'invalid-restore-'.$index]);
            }
        }

        $usage = $order->couponUsage()->firstOrFail();
        try {
            DB::table('coupon_usages')->where('id', $usage->id)->update([
                'status' => 'released', 'released_at' => now(), 'release_reason' => 'invalid-direct-release', 'updated_at' => now(),
            ]);
            $this->fail('Database released a Coupon Usage before a valid COD cancellation.');
        } catch (QueryException) {
            $this->assertSame(CouponUsageStatus::Consumed, $usage->fresh()->status);
        }

        $vnpayUsage = CouponUsage::factory()->consumed()->create();
        try {
            DB::table('coupon_usages')->where('id', $vnpayUsage->id)->update([
                'status' => 'released', 'released_at' => now(), 'release_reason' => 'invalid-vnpay-release', 'updated_at' => now(),
            ]);
            $this->fail('Database released a consumed VNPay Coupon Usage.');
        } catch (QueryException) {
            $this->assertSame(CouponUsageStatus::Consumed, $vnpayUsage->fresh()->status);
        }

        app(CancelCodOrder::class)->handle($order->order_code, $admin, (string) Str::uuid(), 'Valid cancellation');
        $ledger = InventoryTransaction::query()->where('type', InventoryTransactionType::CancelRestore)
            ->where('order_item_id', $item->id)->firstOrFail();
        foreach ([
            fn () => DB::table('inventory_transactions')->where('id', $ledger->id)->update(['reason' => 'changed']),
            fn () => DB::table('inventory_transactions')->where('id', $ledger->id)->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Database mutated an immutable terminal ledger.');
            } catch (QueryException) {
                $this->assertDatabaseHas('inventory_transactions', ['id' => $ledger->id, 'reason' => 'Valid cancellation']);
            }
        }

        foreach ([1, 2] as $suffix) {
            DB::table('inventory_transactions')->insert([
                'product_id' => $product->id, 'type' => 'import', 'sellable_delta' => 1, 'damaged_delta' => 0,
                'source_key' => 'null-item-'.$suffix, 'adjustment_request_id' => null, 'order_item_id' => null,
                'return_inspection_id' => null, 'actor_id' => null, 'reason' => null, 'created_at' => now(),
            ]);
        }
        $this->assertSame(2, InventoryTransaction::query()->whereNull('order_item_id')->where('source_key', 'like', 'null-item-%')->count());
    }

    public function test_terminal_migration_guards_partial_state_round_trips_empty_and_refuses_evidence(): void
    {
        $migration = require database_path('migrations/2026_10_01_000002_enable_cod_terminal_lifecycle.php');
        try {
            $migration->up();
            $this->fail('Existing terminal constraints must be treated as partial state.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('partial state', $exception->getMessage());
        }

        $migration->down();
        $migration->up();
        $this->assertTrue(Schema::hasTable('coupon_usages'));

        $admin = User::factory()->admin()->create();
        [$order, $items] = $this->order(OrderStatus::Placed, [1]);
        $this->completeInspections($order, $items, $admin, [[1, 0]]);
        app(CancelCodOrder::class)->handle($order->order_code, $admin, (string) Str::uuid(), 'Giữ evidence');
        try {
            $migration->down();
            $this->fail('Rollback must preserve terminal evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('evidence', $exception->getMessage());
        }
        $this->assertDatabaseHas('inventory_transactions', ['type' => 'cancel_restore', 'order_item_id' => $items->first()->id]);
    }

    public function test_http_routes_authorization_validation_and_terminal_action_visibility(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create();
        [$order, $items] = $this->order(OrderStatus::InTransit, [1]);
        $this->completeInspections($order, $items, $admin, [[1, 0]]);

        $this->actingAs($admin)->get(route('admin.orders.show', $order->order_code))
            ->assertOk()->assertSee('Hủy đơn và hoàn kho')->assertSee('Xác nhận đã giao')
            ->assertSee('data-submit-once', false)->assertSee('data-confirm-action=', false);
        $this->actingAs($employee)->get(route('employee.orders.show', $order->order_code))
            ->assertOk()->assertDontSee('Hủy đơn và hoàn kho')->assertSee('Xác nhận đã giao');
        auth()->logout();
        $this->patch(route('employee.orders.deliver', $order->order_code))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->patch(route('employee.orders.deliver', $order->order_code), [
            'event_key' => (string) Str::uuid(),
        ])->assertForbidden();

        $response = $this->actingAs($employee)->from(route('employee.orders.show', $order->order_code))
            ->patch(route('employee.orders.deliver', $order->order_code), [
                'event_key' => true, 'reason' => ['bad'], 'status' => 'da_giao',
            ]);
        $response->assertSessionHasErrorsIn('deliverOrder', ['event_key', 'reason', 'request']);
        $this->assertSame(OrderStatus::InTransit, $order->fresh()->status);

        $domainKey = (string) Str::uuid();
        $items->first()->product()->firstOrFail()->forceFill(['sold_quantity' => 4_294_967_295])->save();
        $this->actingAs($employee)->from(route('employee.orders.show', $order->order_code))
            ->patch(route('employee.orders.deliver', $order->order_code), [
                'event_key' => $domainKey, 'reason' => 'Retry same event',
            ])->assertSessionHasErrorsIn('deliverOrder', ['inventory'])
            ->assertSessionHasInput('event_key', $domainKey);

        $conflictingKey = $order->statusHistories()->latest('created_at')->latest('id')->firstOrFail()->event_key;
        $this->actingAs($employee)->from(route('employee.orders.show', $order->order_code))
            ->patch(route('employee.orders.deliver', $order->order_code), [
                'event_key' => $conflictingKey,
            ])->assertSessionHasErrorsIn('deliverOrder', ['event_key']);
        $this->get(route('employee.orders.show', $order->order_code))
            ->assertOk()->assertDontSee('value="'.$conflictingKey.'"', false);

        [$withoutInspection] = $this->order(OrderStatus::Placed, [1]);
        $cancelKey = (string) Str::uuid();
        $this->actingAs($employee)->from(route('employee.orders.show', $withoutInspection->order_code))
            ->patch(route('employee.orders.cancel', $withoutInspection->order_code), [
                'event_key' => $cancelKey, 'reason' => 'Chưa kiểm tra',
            ])->assertSessionHasErrorsIn('cancelOrder', ['inspection'])
            ->assertSessionHasInput('event_key', $cancelKey);

        $customer = $order->customer()->firstOrFail();
        $this->actingAs($customer)->get(route('orders.show', $order->order_code))
            ->assertOk()->assertDontSee('Hủy đơn và hoàn kho')->assertDontSee('Xác nhận đã giao');
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a domain rejection.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    /** @return array{Order, Collection<int, OrderItem>, Collection<int, Product>} */
    private function order(OrderStatus $status, array $quantities, bool $withCoupon = false, int $unitPrice = 100_000): array
    {
        $customer = User::factory()->create();
        $subtotal = array_sum($quantities) * $unitPrice;
        $coupon = $withCoupon ? Coupon::factory()->create() : null;
        $order = Order::factory()->for($customer, 'customer')->create([
            'status' => $status,
            'coupon_id' => $coupon?->id,
            'coupon_snapshot_json' => $coupon === null ? null : [
                'coupon_id' => $coupon->id, 'code' => $coupon->code, 'type' => $coupon->type->value,
                'scope' => $coupon->scope->value, 'value' => $coupon->value, 'eligible_subtotal_vnd' => $subtotal,
            ],
            'items_subtotal_vnd' => $subtotal, 'item_discount_vnd' => 0,
            'shipping_fee_vnd' => 30_000, 'shipping_discount_vnd' => 0, 'total_vnd' => $subtotal + 30_000,
            'delivered_at' => null,
            'payment_status' => PaymentStatus::Unpaid,
        ]);
        if ($status === OrderStatus::Delivered) {
            $order->forceFill(['payment_status' => PaymentStatus::Paid, 'delivered_at' => now()])->save();
        }

        $items = collect();
        $products = collect();
        foreach ($quantities as $index => $quantity) {
            $product = Product::factory()->create([
                'sku' => 'TERMINAL-'.$order->id.'-'.$index,
                'sellable_quantity' => 5, 'damaged_quantity' => 1, 'sold_quantity' => 4,
            ]);
            $item = OrderItem::factory()->for($order)->for($product)->create([
                'quantity' => $quantity, 'unit_price_vnd' => $unitPrice,
                'line_subtotal_vnd' => $quantity * $unitPrice, 'discount_vnd' => 0,
                'line_total_vnd' => $quantity * $unitPrice,
            ]);
            $items->push($item);
            $products->push($product);
        }
        $this->createHistoryChain($order, $status);

        if ($coupon !== null) {
            (new CouponUsage)->forceFill([
                'coupon_id' => $coupon->id, 'customer_id' => $customer->id,
                'payment_attempt_id' => null, 'order_id' => $order->id,
                'status' => CouponUsageStatus::Consumed, 'reserved_at' => null, 'expires_at' => null,
                'consumed_at' => now(), 'released_at' => null, 'release_reason' => null,
                'late_callback_exception' => false, 'created_at' => now(), 'updated_at' => now(),
            ])->save();
        }

        return [$order, $items, $products];
    }

    private function createHistoryChain(Order $order, OrderStatus $target): void
    {
        $edges = [[null, OrderStatus::Placed]];
        if (in_array($target, [OrderStatus::AwaitingHandoff, OrderStatus::InTransit, OrderStatus::Delivered], true)) {
            $edges[] = [OrderStatus::Placed, OrderStatus::AwaitingHandoff];
        }
        if (in_array($target, [OrderStatus::InTransit, OrderStatus::Delivered], true)) {
            $edges[] = [OrderStatus::AwaitingHandoff, OrderStatus::InTransit];
        }
        if ($target === OrderStatus::Delivered) {
            $edges[] = [OrderStatus::InTransit, OrderStatus::Delivered];
        }
        if ($target === OrderStatus::Cancelled) {
            $edges[] = [OrderStatus::Placed, OrderStatus::Cancelled];
        }
        foreach ($edges as $index => [$from, $to]) {
            (new OrderStatusHistory)->forceFill([
                'order_id' => $order->id, 'from_status' => $from, 'to_status' => $to,
                'actor_id' => null, 'reason' => null, 'event_key' => (string) Str::uuid(),
                'created_at' => now()->addMicroseconds($index),
            ])->save();
        }
    }

    private function completeInspections(Order $order, $items, User $actor, array $splits): void
    {
        foreach ($items->values() as $index => $item) {
            app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $actor, now(), 'Đã nhận hàng');
            app(CompleteReturnInspection::class)->handle($order->order_code, $item->id, $actor, $splits[$index][0], $splits[$index][1], 'Đã phân loại');
        }
    }
}
