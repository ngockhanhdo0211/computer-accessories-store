<?php

namespace Tests\Feature;

use App\Actions\CompleteReturnInspection;
use App\Actions\OrderHasCompletedReturnInspections;
use App\Actions\ReceiveReturnInspection;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\CouponUsage;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnInspection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ReturnInspectionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_relationships_and_two_stage_lifecycle_are_valid(): void
    {
        [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 3);
        $employee = User::factory()->employee()->create();
        $receivedAt = CarbonImmutable::parse('2026-10-01 01:00:00 UTC');
        $this->travelTo($receivedAt);

        $inspection = app(ReceiveReturnInspection::class)->handle(
            $order->order_code, $item->id, $employee, (string) Str::uuid(), 'Đã nhận tại kho.',
        );

        $this->assertFalse($inspection->completed);
        $this->assertNull($inspection->sellable_quantity);
        $this->assertTrue($inspection->orderItem->is($item));
        $this->assertTrue($inspection->receiver->is($employee));
        $this->assertNull($inspection->inspector);
        $this->assertTrue($item->fresh()->returnInspection->is($inspection));

        $inspection = app(CompleteReturnInspection::class)->handle(
            $order->order_code, $item->id, $employee, (string) Str::uuid(), 2, 1, 'Một sản phẩm hỏng.',
        );
        $this->travelBack();

        $this->assertTrue($inspection->completed);
        $this->assertSame(2, $inspection->sellable_quantity);
        $this->assertSame(1, $inspection->damaged_quantity);
        $this->assertTrue($inspection->inspector->is($employee));
        $this->assertDatabaseCount('return_inspections', 1);
        $this->assertDatabaseCount('audit_logs', 2);
        $receiveAudit = AuditLog::query()->where('action', 'return_inspection.received')->firstOrFail();
        $completeAudit = AuditLog::query()->where('action', 'return_inspection.completed')->firstOrFail();
        $this->assertSame($employee->id, $receiveAudit->actor_id);
        $this->assertSame(ReturnInspection::class, $receiveAudit->subject_type);
        $this->assertSame($inspection->id, $receiveAudit->subject_id);
        $this->assertSame('Đã nhận tại kho.', $receiveAudit->after_json['note']);
        $this->assertSame(['state' => 'pending', 'note' => 'Đã nhận tại kho.'], $completeAudit->before_json);
        $this->assertSame('completed', $completeAudit->after_json['state']);
        $this->assertSame([2, 1], [
            $completeAudit->after_json['sellable_quantity'],
            $completeAudit->after_json['damaged_quantity'],
        ]);
        foreach ([$receiveAudit->before_json, $receiveAudit->after_json, $completeAudit->before_json, $completeAudit->after_json] as $payload) {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            foreach (['password', 'session', 'cookie', 'csrf', 'request_key', 'credential'] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, strtolower($encoded));
            }
        }
        $this->assertTrue(Schema::hasColumn('inventory_transactions', 'return_inspection_id'));
        $this->assertCount(0, $inspection->inventoryTransactions);
    }

    public function test_database_rejects_invalid_shapes_quantities_time_unique_and_foreign_keys(): void
    {
        [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 2);
        $admin = User::factory()->admin()->create();
        $base = $this->pendingRow($item, $admin);

        foreach ([
            [
                'receive_event_key' => (string) Str::uuid(),
                'receive_fingerprint' => null,
            ],
            [
                'receive_event_key' => 'not-a-uuid',
                'receive_fingerprint' => hash('sha256', 'invalid-key'),
            ],
            [
                'receive_event_key' => strtoupper((string) Str::uuid()),
                'receive_fingerprint' => hash('sha256', 'uppercase-key'),
            ],
            [
                'complete_event_key' => (string) Str::uuid(),
                'complete_fingerprint' => hash('sha256', 'pending-cannot-be-complete'),
            ],
            ['inspected_by' => $admin->id],
            ['sellable_quantity' => -1],
            [
                'inspected_by' => $admin->id,
                'inspected_at' => CarbonImmutable::parse('2026-09-30 23:00:00 UTC'),
                'sellable_quantity' => 2,
                'damaged_quantity' => 0,
            ],
            [
                'inspected_by' => $admin->id,
                'inspected_at' => CarbonImmutable::parse('2026-10-01 02:00:00 UTC'),
                'sellable_quantity' => 1,
                'damaged_quantity' => 0,
            ],
            [
                'inspected_by' => $admin->id,
                'inspected_at' => CarbonImmutable::parse('2026-10-01 02:00:00 UTC'),
                'sellable_quantity' => 2,
                'damaged_quantity' => 0,
            ],
        ] as $invalid) {
            $this->assertQueryFails(fn () => DB::table('return_inspections')->insert(array_merge($base, $invalid)));
        }

        DB::table('return_inspections')->insert($base);
        $this->assertQueryFails(fn () => DB::table('return_inspections')->insert($base));
        $this->assertQueryFails(fn () => DB::table('return_inspections')->where('order_item_id', $item->id)->update([
            'note' => 'Không được sửa pending',
        ]));
        $this->assertQueryFails(fn () => DB::table('return_inspections')->where('order_item_id', $item->id)->update([
            'inspected_by' => $admin->id,
        ]));
        $this->assertQueryFails(fn () => DB::table('return_inspections')->where('order_item_id', $item->id)->update([
            'inspected_by' => $admin->id,
            'inspected_at' => CarbonImmutable::parse('2026-10-01 02:00:00 UTC'),
            'sellable_quantity' => 1,
            'damaged_quantity' => 0,
            'updated_at' => now()->addSecond(),
        ]));
        $this->assertQueryFails(fn () => DB::table('return_inspections')->where('order_item_id', $item->id)->update([
            'inspected_by' => $admin->id,
            'inspected_at' => CarbonImmutable::parse('2026-09-30 23:00:00 UTC'),
            'sellable_quantity' => 2,
            'damaged_quantity' => 0,
            'updated_at' => now()->addSecond(),
        ]));
        $this->assertQueryFails(fn () => DB::table('return_inspections')->insert(array_merge($base, [
            'order_item_id' => 999_999,
        ])));
        $this->assertQueryFails(fn () => DB::table('return_inspections')->where('order_item_id', $item->id)->update([
            'received_by' => User::factory()->admin()->create()->id,
        ]));

        $this->assertSame(OrderStatus::Placed, $order->fresh()->status);
    }

    public function test_database_and_model_allow_only_one_pending_to_completed_update(): void
    {
        [$order, $item] = $this->orderWithItem(OrderStatus::AwaitingHandoff, 2);
        $admin = User::factory()->admin()->create();
        $receivedAt = CarbonImmutable::parse('2026-10-01 01:00:00 UTC');
        $this->travelTo($receivedAt);
        $inspection = app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $admin, (string) Str::uuid());
        $this->travelBack();

        try {
            $inspection->forceFill(['received_at' => $receivedAt->addMinute()])->save();
            $this->fail('Received fields must be immutable.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $completed = app(CompleteReturnInspection::class)->handle(
            $order->order_code, $item->id, $admin, (string) Str::uuid(), 2, 0, 'Hoàn tất.',
        );

        foreach ([
            ['inspected_by' => null, 'inspected_at' => null, 'sellable_quantity' => null, 'damaged_quantity' => null],
            ['sellable_quantity' => 1, 'damaged_quantity' => 1],
            ['note' => 'Sửa sau hoàn tất'],
        ] as $change) {
            $this->assertQueryFails(fn () => DB::table('return_inspections')->where('id', $completed->id)->update($change));
        }
        $this->assertQueryFails(fn () => DB::table('return_inspections')->where('id', $completed->id)->delete());

        try {
            $completed->forceFill(['note' => 'Model mutation'])->save();
            $this->fail('Completed inspection must be immutable in the model.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        try {
            $completed->delete();
            $this->fail('Inspection delete must be blocked in the model.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
    }

    public function test_current_actor_role_status_and_order_status_matrix_is_enforced(): void
    {
        foreach ([OrderStatus::Placed, OrderStatus::AwaitingHandoff] as $status) {
            foreach ([User::factory()->employee()->create(), User::factory()->admin()->create()] as $actor) {
                [$order, $item] = $this->orderWithItem($status);
                $this->assertInstanceOf(ReturnInspection::class, app(ReceiveReturnInspection::class)->handle(
                    $order->order_code, $item->id, $actor, (string) Str::uuid(),
                ));
            }
        }

        [$transit, $transitItem] = $this->orderWithItem(OrderStatus::InTransit);
        $admin = User::factory()->admin()->create();
        app(ReceiveReturnInspection::class)->handle($transit->order_code, $transitItem->id, $admin, (string) Str::uuid());

        foreach ([
            [OrderStatus::InTransit, User::factory()->employee()->create()],
            [OrderStatus::Delivered, User::factory()->admin()->create()],
            [OrderStatus::Cancelled, User::factory()->admin()->create()],
            [OrderStatus::Placed, User::factory()->create()],
            [OrderStatus::Placed, User::factory()->admin()->locked()->create()],
            [OrderStatus::Placed, User::factory()->employee()->inactive()->create()],
        ] as [$status, $actor]) {
            [$order, $item] = $this->orderWithItem($status);
            $this->assertValidationFails(
                fn () => app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $actor, (string) Str::uuid()),
                'authorization',
            );
        }

        [$order, $item] = $this->orderWithItem(OrderStatus::Placed);
        $staleAdmin = User::factory()->admin()->create();
        DB::table('users')->where('id', $staleAdmin->id)->update(['status' => 'locked']);
        $this->assertValidationFails(
            fn () => app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $staleAdmin, (string) Str::uuid()),
            'authorization',
        );

        foreach ([['role', 'unknown'], ['status', 'unknown']] as [$field, $value]) {
            [$order, $item] = $this->orderWithItem(OrderStatus::Placed);
            $invalidActor = User::factory()->admin()->create();
            DB::statement('PRAGMA ignore_check_constraints = ON');
            try {
                DB::table('users')->where('id', $invalidActor->id)->update([$field => $value]);
                $this->assertValidationFails(
                    fn () => app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $invalidActor, (string) Str::uuid()),
                    'authorization',
                );
                DB::table('users')->where('id', $invalidActor->id)->update([
                    'role' => 'admin',
                    'status' => 'active',
                ]);
            } finally {
                DB::statement('PRAGMA ignore_check_constraints = OFF');
            }
        }
    }

    public function test_receive_and_complete_are_idempotent_and_reject_conflicts(): void
    {
        [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 4);
        $admin = User::factory()->admin()->create();
        $receive = app(ReceiveReturnInspection::class);
        $complete = app(CompleteReturnInspection::class);
        $receiveKey = (string) Str::uuid();
        $completeKey = (string) Str::uuid();

        $first = $receive->handle($order->order_code, $item->id, $admin, $receiveKey, 'Nhận');
        $this->assertTrue($first->is($receive->handle($order->order_code, $item->id, $admin, $receiveKey, 'Nhận')));
        $this->assertDatabaseCount('return_inspections', 1);
        $this->assertDatabaseCount('audit_logs', 1);

        $this->assertValidationFails(
            fn () => $receive->handle($order->order_code, $item->id, $admin, $receiveKey, 'Khác'),
            'event_key',
        );
        $otherAdmin = User::factory()->admin()->create();
        $this->assertValidationFails(
            fn () => $receive->handle($order->order_code, $item->id, $otherAdmin, (string) Str::uuid(), 'Nhận'),
            'event_key',
        );

        $result = $complete->handle($order->order_code, $item->id, $admin, $completeKey, 3, 1, 'Hoàn tất');
        $this->assertTrue($result->is($complete->handle($order->order_code, $item->id, $admin, $completeKey, 3, 1, 'Hoàn tất')));
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertTrue($first->is($receive->handle($order->order_code, $item->id, $admin, $receiveKey, 'Nhận')));
        $this->assertDatabaseCount('audit_logs', 2);

        $this->assertValidationFails(
            fn () => $complete->handle($order->order_code, $item->id, $admin, $completeKey, 4, 0, 'Hoàn tất'),
            'event_key',
        );
        $this->assertValidationFails(
            fn () => $complete->handle($order->order_code, $item->id, $admin, $completeKey, 3, 1, 'Khác'),
            'event_key',
        );
        $this->assertValidationFails(
            fn () => $complete->handle($order->order_code, $item->id, $otherAdmin, (string) Str::uuid(), 3, 1, 'Hoàn tất'),
            'event_key',
        );
    }

    public function test_complete_rejects_non_integer_negative_and_overflow_quantities_and_uses_server_time(): void
    {
        [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 2);
        $admin = User::factory()->admin()->create();
        app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $admin, (string) Str::uuid());
        $action = app(CompleteReturnInspection::class);

        foreach ([
            ['2', 0],
            [2.0, 0],
            [true, 1],
            ['2e0', 0],
            [[2], 0],
            [-1, 3],
            [PHP_INT_MAX, 1],
        ] as [$sellable, $damaged]) {
            $this->assertValidationFails(
                fn () => $action->handle($order->order_code, $item->id, $admin, (string) Str::uuid(), $sellable, $damaged),
                'quantity',
            );
        }

        $serverNow = now()->addHour()->toImmutable();
        $this->travelTo($serverNow);
        try {
            $inspection = $action->handle($order->order_code, $item->id, $admin, (string) Str::uuid(), 2, 0);
        } finally {
            $this->travelBack();
        }

        $this->assertTrue($inspection->inspected_at->equalTo($serverNow));
    }

    public function test_actions_reject_foreign_item_and_invalid_completion_without_writes(): void
    {
        [$order] = $this->orderWithItem(OrderStatus::Placed);
        [, $foreignItem] = $this->orderWithItem(OrderStatus::Placed);
        $admin = User::factory()->admin()->create();

        $this->assertValidationFails(
            fn () => app(ReceiveReturnInspection::class)->handle($order->order_code, $foreignItem->id, $admin, (string) Str::uuid()),
            'order_item',
        );
        $this->assertValidationFails(
            fn () => app(CompleteReturnInspection::class)->handle($order->order_code, $foreignItem->id, $admin, (string) Str::uuid(), 2, 0),
            'order_item',
        );
        $this->assertDatabaseCount('return_inspections', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_readiness_requires_every_item_to_have_a_reconciled_completed_inspection(): void
    {
        [$order, $first] = $this->orderWithItem(OrderStatus::Placed, 2);
        $second = OrderItem::factory()->for($order)->create(['quantity' => 3, 'line_subtotal_vnd' => 300_000, 'line_total_vnd' => 300_000]);
        $admin = User::factory()->admin()->create();
        $ready = app(OrderHasCompletedReturnInspections::class);

        $this->assertFalse($ready->handle($order));
        app(ReceiveReturnInspection::class)->handle($order->order_code, $first->id, $admin, (string) Str::uuid());
        app(CompleteReturnInspection::class)->handle($order->order_code, $first->id, $admin, (string) Str::uuid(), 2, 0);
        $this->assertFalse($ready->handle($order));
        app(ReceiveReturnInspection::class)->handle($order->order_code, $second->id, $admin, (string) Str::uuid());
        $this->assertFalse($ready->handle($order));
        app(CompleteReturnInspection::class)->handle($order->order_code, $second->id, $admin, (string) Str::uuid(), 2, 1);
        $this->assertTrue($ready->handle($order));
    }

    public function test_inspection_is_atomic_and_has_no_order_inventory_payment_coupon_membership_or_cart_effect(): void
    {
        [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 2);
        $admin = User::factory()->admin()->create();
        $beforeOrder = $order->getAttributes();
        $beforeProduct = $item->product->getAttributes();
        $beforeCustomer = $order->customer->getAttributes();
        $beforeCounts = [
            InventoryTransaction::query()->count(),
            CouponUsage::query()->count(),
            DB::table('cart_items')->count(),
            DB::table('payment_attempts')->count(),
            DB::table('stock_reservations')->count(),
            DB::table('membership_histories')->count(),
            DB::table('order_status_histories')->count(),
        ];
        app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $admin, (string) Str::uuid());
        app(CompleteReturnInspection::class)->handle($order->order_code, $item->id, $admin, (string) Str::uuid(), 1, 1);

        $this->assertEquals($beforeOrder, $order->fresh()->getAttributes());
        $this->assertEquals($beforeProduct, $item->product->fresh()->getAttributes());
        $this->assertEquals($beforeCustomer, $order->customer->fresh()->getAttributes());
        $this->assertSame($beforeCounts, [
            InventoryTransaction::query()->count(),
            CouponUsage::query()->count(),
            DB::table('cart_items')->count(),
            DB::table('payment_attempts')->count(),
            DB::table('stock_reservations')->count(),
            DB::table('membership_histories')->count(),
            DB::table('order_status_histories')->count(),
        ]);
    }

    public function test_audit_failure_rolls_back_receive_atomically(): void
    {
        [$order, $item] = $this->orderWithItem(OrderStatus::Placed);
        $admin = User::factory()->admin()->create();
        Event::listen('eloquent.creating: '.AuditLog::class, fn () => throw new RuntimeException('forced audit failure'));

        try {
            app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $admin, (string) Str::uuid());
            $this->fail('Audit failure must roll back Return Inspection.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced audit failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('return_inspections', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_complete_enforces_full_current_role_status_and_order_status_matrix(): void
    {
        $at = CarbonImmutable::parse('2026-10-01 01:00:00 UTC');

        foreach ([OrderStatus::Placed, OrderStatus::AwaitingHandoff] as $status) {
            foreach ([User::factory()->employee()->create(), User::factory()->admin()->create()] as $actor) {
                [$order, $item] = $this->orderWithItem($status, 2);
                app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $actor, (string) Str::uuid());
                $this->assertTrue(app(CompleteReturnInspection::class)
                    ->handle($order->order_code, $item->id, $actor, (string) Str::uuid(), 2, 0)
                    ->isCompleted());
            }
        }

        [$transit, $transitItem] = $this->orderWithItem(OrderStatus::InTransit, 2);
        $admin = User::factory()->admin()->create();
        app(ReceiveReturnInspection::class)->handle($transit->order_code, $transitItem->id, $admin, (string) Str::uuid());
        $this->assertTrue(app(CompleteReturnInspection::class)
            ->handle($transit->order_code, $transitItem->id, $admin, (string) Str::uuid(), 2, 0)
            ->isCompleted());

        foreach ([
            [OrderStatus::Placed, User::factory()->create()],
            [OrderStatus::InTransit, User::factory()->employee()->create()],
        ] as [$status, $actor]) {
            [$order, $item] = $this->orderWithItem($status, 2);
            $receiver = User::factory()->admin()->create();
            app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $receiver, (string) Str::uuid());
            $this->assertValidationFails(
                fn () => app(CompleteReturnInspection::class)->handle($order->order_code, $item->id, $actor, (string) Str::uuid(), 2, 0),
                'authorization',
            );
        }

        foreach ([OrderStatus::Delivered, OrderStatus::Cancelled] as $status) {
            [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 2);
            $actor = User::factory()->admin()->create();
            app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $actor, (string) Str::uuid());
            DB::table('orders')->where('id', $order->id)->update(['status' => $status->value]);
            $this->assertValidationFails(
                fn () => app(CompleteReturnInspection::class)->handle($order->order_code, $item->id, $actor, (string) Str::uuid(), 2, 0),
                'authorization',
            );
        }

        foreach (['locked', 'inactive'] as $status) {
            [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 2);
            $actor = User::factory()->admin()->create();
            app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $actor, (string) Str::uuid());
            DB::table('users')->where('id', $actor->id)->update(['status' => $status]);
            $this->assertValidationFails(
                fn () => app(CompleteReturnInspection::class)->handle($order->order_code, $item->id, $actor, (string) Str::uuid(), 2, 0),
                'authorization',
            );
        }

        foreach ([['role', 'unknown'], ['status', 'unknown']] as [$field, $value]) {
            [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 2);
            $actor = User::factory()->admin()->create();
            app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $actor, (string) Str::uuid());
            DB::statement('PRAGMA ignore_check_constraints = ON');
            try {
                DB::table('users')->where('id', $actor->id)->update([$field => $value]);
                $this->assertValidationFails(
                    fn () => app(CompleteReturnInspection::class)->handle($order->order_code, $item->id, $actor, (string) Str::uuid(), 2, 0),
                    'authorization',
                );
                DB::table('users')->where('id', $actor->id)->update([
                    'role' => 'admin',
                    'status' => 'active',
                ]);
            } finally {
                DB::statement('PRAGMA ignore_check_constraints = OFF');
            }
        }
    }

    public function test_audit_failure_rolls_back_completion_atomically(): void
    {
        [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 2);
        $admin = User::factory()->admin()->create();
        $inspection = app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $admin, (string) Str::uuid());
        Event::listen('eloquent.creating: '.AuditLog::class, fn () => throw new RuntimeException('forced completion audit failure'));

        try {
            app(CompleteReturnInspection::class)->handle($order->order_code, $item->id, $admin, (string) Str::uuid(), 2, 0);
            $this->fail('Audit failure must roll back Return Inspection completion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced completion audit failure', $exception->getMessage());
        }

        $this->assertFalse($inspection->fresh()->isCompleted());
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_migration_guards_partial_state_and_round_trips_only_when_empty(): void
    {
        $migration = require database_path('migrations/2026_10_01_000001_create_return_inspections_table.php');
        $terminalMigration = require database_path('migrations/2026_10_01_000002_enable_cod_terminal_lifecycle.php');

        try {
            $migration->up();
            $this->fail('Existing Return Inspection structures must be treated as partial state.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('partial migration state', $exception->getMessage());
        }

        $terminalMigration->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('return_inspections'));
        $this->assertFalse(Schema::hasColumn('inventory_transactions', 'return_inspection_id'));
        $migration->up();
        $terminalMigration->up();
        $this->assertTrue(Schema::hasTable('return_inspections'));
        $this->assertTrue(Schema::hasColumn('inventory_transactions', 'return_inspection_id'));
    }

    public function test_migration_down_refuses_to_delete_inspection_evidence(): void
    {
        [$order, $item] = $this->orderWithItem(OrderStatus::Placed);
        $admin = User::factory()->admin()->create();
        $inspection = app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $admin, (string) Str::uuid());
        $migration = require database_path('migrations/2026_10_01_000001_create_return_inspections_table.php');

        try {
            $migration->down();
            $this->fail('Rollback must not delete inspection evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No inspection was deleted', $exception->getMessage());
        }

        $this->assertDatabaseHas('return_inspections', ['id' => $inspection->id]);
    }

    public function test_migration_down_refuses_to_remove_a_referenced_inspection_source(): void
    {
        [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 2);
        $admin = User::factory()->admin()->create();
        $inspection = app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $admin, (string) Str::uuid());
        $inspection = app(CompleteReturnInspection::class)->handle($order->order_code, $item->id, $admin, (string) Str::uuid(), 2, 0);
        DB::table('inventory_transactions')->insert([
            'product_id' => $item->product_id,
            'type' => 'cancel_restore',
            'sellable_delta' => 2,
            'damaged_delta' => 0,
            'source_key' => 'return-inspection-rollback-guard',
            'adjustment_request_id' => null,
            'order_item_id' => $item->id,
            'return_inspection_id' => $inspection->id,
            'actor_id' => $admin->id,
            'reason' => 'Controlled migration rollback fixture',
            'created_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_10_01_000001_create_return_inspections_table.php');

        try {
            $migration->down();
            $this->fail('Rollback must not remove a Return Inspection source referenced by ledger.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ledger rows reference Return Inspections', $exception->getMessage());
        }

        $this->assertDatabaseHas('return_inspections', ['id' => $inspection->id]);
        $this->assertDatabaseHas('inventory_transactions', ['return_inspection_id' => $inspection->id]);
    }

    public function test_idempotency_migration_guards_partial_state_and_evidence_rollback(): void
    {
        $migration = require database_path('migrations/2026_10_08_000000_add_idempotency_to_return_inspections.php');
        $migration->down();

        Schema::table('return_inspections', fn ($table) => $table->char('receive_event_key', 36)->nullable());
        try {
            $migration->up();
            $this->fail('Partial idempotency metadata must stop migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('partial or previously applied state', $exception->getMessage());
        }
        Schema::table('return_inspections', fn ($table) => $table->dropColumn('receive_event_key'));
        $migration->up();

        [$order, $item] = $this->orderWithItem(OrderStatus::Placed);
        $admin = User::factory()->admin()->create();
        app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $admin, (string) Str::uuid());
        try {
            $migration->down();
            $this->fail('Rollback must preserve idempotency evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('idempotency evidence', $exception->getMessage());
        }
    }

    public function test_historical_completed_row_without_event_metadata_remains_readable_but_is_not_replayable(): void
    {
        $migration = require database_path('migrations/2026_10_08_000000_add_idempotency_to_return_inspections.php');
        $migration->down();
        [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 2);
        $admin = User::factory()->admin()->create();
        $receivedAt = now()->subMinute();
        $id = DB::table('return_inspections')->insertGetId([
            'order_item_id' => $item->id,
            'received_by' => $admin->id,
            'received_at' => $receivedAt,
            'inspected_by' => null,
            'inspected_at' => null,
            'sellable_quantity' => null,
            'damaged_quantity' => null,
            'note' => 'Historical complete',
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);
        DB::table('return_inspections')->where('id', $id)->update([
            'inspected_by' => $admin->id,
            'inspected_at' => now(),
            'sellable_quantity' => 2,
            'damaged_quantity' => 0,
            'updated_at' => now(),
        ]);
        $migration->up();

        $historical = ReturnInspection::query()->findOrFail($id);
        $this->assertTrue($historical->isCompleted());
        $this->assertNull($historical->receive_event_key);
        $this->assertNull($historical->complete_event_key);
        $this->assertValidationFails(
            fn () => app(CompleteReturnInspection::class)->handle(
                $order->order_code, $item->id, $admin, (string) Str::uuid(), 2, 0, 'Historical complete'
            ),
            'event_key',
        );
    }

    /** @return array{Order, OrderItem} */
    private function orderWithItem(OrderStatus $status, int $quantity = 2): array
    {
        $order = Order::factory()->create(['status' => $status]);
        $item = OrderItem::factory()->for($order)->create([
            'quantity' => $quantity,
            'line_subtotal_vnd' => 100_000 * $quantity,
            'line_total_vnd' => 100_000 * $quantity,
        ]);

        return [$order, $item];
    }

    /** @return array<string, mixed> */
    private function pendingRow(OrderItem $item, User $receiver): array
    {
        return [
            'order_item_id' => $item->id,
            'received_by' => $receiver->id,
            'received_at' => CarbonImmutable::parse('2026-10-01 01:00:00 UTC'),
            'inspected_by' => null,
            'inspected_at' => null,
            'sellable_quantity' => null,
            'damaged_quantity' => null,
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function assertQueryFails(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Database accepted an invalid Return Inspection mutation.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    private function assertValidationFails(callable $operation, string $key): void
    {
        try {
            $operation();
            $this->fail('Return Inspection action accepted an invalid operation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
