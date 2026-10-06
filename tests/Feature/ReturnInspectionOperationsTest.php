<?php

namespace Tests\Feature;

use App\Actions\CompleteReturnInspection;
use App\Actions\ReceiveReturnInspection;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnInspection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReturnInspectionOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_use_role_boundaries_and_mutating_http_methods(): void
    {
        foreach (['admin', 'employee'] as $prefix) {
            $show = Route::getRoutes()->getByName($prefix.'.orders.return-inspections.show');
            $receive = Route::getRoutes()->getByName($prefix.'.orders.return-inspections.receive');
            $complete = Route::getRoutes()->getByName($prefix.'.orders.return-inspections.complete');

            $this->assertSame(['GET', 'HEAD'], $show->methods());
            $this->assertSame(['POST'], $receive->methods());
            $this->assertSame(['PATCH'], $complete->methods());
            $this->assertSame('[A-Za-z0-9][A-Za-z0-9-]{0,39}', $show->wheres['orderCode']);
            $this->assertSame($show->wheres['orderCode'], $receive->wheres['orderCode']);
            $this->assertSame($show->wheres['orderCode'], $complete->wheres['orderCode']);
            $this->assertSame('[0-9]+', $receive->wheres['orderItem']);
            $this->assertSame('[0-9]+', $complete->wheres['orderItem']);
            $this->assertContains('role:'.$prefix, $show->gatherMiddleware());
            $this->assertContains('role:'.$prefix, $receive->gatherMiddleware());
            $this->assertContains('role:'.$prefix, $complete->gatherMiddleware());
        }
    }

    public function test_access_matrix_and_cross_order_item_are_enforced_by_http_boundary(): void
    {
        [$placed, $placedItem] = $this->orderWithItem(OrderStatus::Placed);
        [$transit, $transitItem] = $this->orderWithItem(OrderStatus::InTransit);
        $customer = User::factory()->create();
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();

        $this->get(route('admin.orders.return-inspections.show', $placed->order_code))->assertRedirect(route('login'));
        $this->actingAs($customer)->get(route('admin.orders.return-inspections.show', $placed->order_code))->assertForbidden();
        $this->actingAs($employee)->get(route('employee.orders.return-inspections.show', $placed->order_code))->assertOk();
        $this->get(route('employee.orders.return-inspections.show', $transit->order_code))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.orders.return-inspections.show', $transit->order_code))->assertOk();

        $this->actingAs($employee)->post(route('employee.orders.return-inspections.receive', [$placed->order_code, $transitItem->id]), [
            'event_key' => (string) Str::uuid(),
        ])->assertSessionHasErrorsIn('receive-'.$transitItem->id, 'order_item');
        $this->assertDatabaseMissing('return_inspections', ['order_item_id' => $transitItem->id]);

        foreach ([User::factory()->employee()->locked()->create(), User::factory()->admin()->inactive()->create()] as $blocked) {
            $prefix = $blocked->isAdmin() ? 'admin' : 'employee';
            $this->actingAs($blocked)->get(route($prefix.'.orders.return-inspections.show', $placed->order_code))
                ->assertRedirect(route('login'));
        }

        $this->assertDatabaseMissing('return_inspections', ['order_item_id' => $placedItem->id]);
    }

    public function test_receive_and_complete_forms_preserve_separate_keys_and_render_completed_evidence_read_only(): void
    {
        [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 3, '<script>alert(1)</script>', 'SKU-<unsafe>');
        $admin = User::factory()->admin()->create(['name' => '<b>Quản trị</b>']);
        $receiveKey = (string) Str::uuid();

        $page = $this->actingAs($admin)->get(route('admin.orders.return-inspections.show', $order->order_code));
        $page->assertOk()->assertSee('method="POST"', false)->assertSee('name="_token"', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('inspection-card');

        $this->post(route('admin.orders.return-inspections.receive', [$order->order_code, $item->id]), [
            'event_key' => $receiveKey,
            'note' => str_repeat('a', 501),
        ])->assertSessionHasErrorsIn('receive-'.$item->id, 'note')->assertSessionHasInput('event_key', $receiveKey);

        $this->post(route('admin.orders.return-inspections.receive', [$order->order_code, $item->id]), [
            'event_key' => $receiveKey,
            'note' => '  Hàng về kho  ',
        ])->assertRedirect();
        $inspection = ReturnInspection::query()->where('order_item_id', $item->id)->firstOrFail();
        $this->assertSame($receiveKey, $inspection->receive_event_key);
        $this->assertSame('Hàng về kho', $inspection->note);
        $this->assertDatabaseHas('audit_logs', ['request_id' => $receiveKey, 'action' => 'return_inspection.received']);
        $this->get(route('admin.orders.return-inspections.show', $order->order_code))
            ->assertOk()->assertSee('name="_method" value="PATCH"', false)
            ->assertSee('inspection-quantity-grid', false);

        $this->patch(route('admin.orders.return-inspections.complete', [$order->order_code, $item->id]), [
            'event_key' => $receiveKey,
            'sellable_quantity' => '2',
            'damaged_quantity' => '1',
            'received_at' => now()->toIso8601String(),
        ])->assertSessionHasErrorsIn('complete-'.$item->id, 'request');

        $completeKey = (string) Str::uuid();
        $this->patch(route('admin.orders.return-inspections.complete', [$order->order_code, $item->id]), [
            'event_key' => $receiveKey,
            'sellable_quantity' => '2',
            'damaged_quantity' => '1',
        ])->assertSessionHasErrorsIn('complete-'.$item->id, 'event_key');

        $this->patch(route('admin.orders.return-inspections.complete', [$order->order_code, $item->id]), [
            'event_key' => $completeKey,
            'sellable_quantity' => '1',
            'damaged_quantity' => '1',
        ])->assertSessionHasErrorsIn('complete-'.$item->id, 'quantity')->assertSessionHasInput('event_key', $completeKey);

        $this->patch(route('admin.orders.return-inspections.complete', [$order->order_code, $item->id]), [
            'event_key' => $completeKey,
            'sellable_quantity' => '2',
            'damaged_quantity' => '1',
            'note' => '<img src=x onerror=alert(1)>',
        ])->assertRedirect();

        $completed = $inspection->fresh();
        $this->assertTrue($completed->isCompleted());
        $this->assertSame($completeKey, $completed->complete_event_key);
        $this->assertDatabaseHas('audit_logs', ['request_id' => $completeKey, 'action' => 'return_inspection.completed']);

        $this->get(route('admin.orders.return-inspections.show', $order->order_code))
            ->assertOk()->assertSee('&lt;b&gt;Quản trị&lt;/b&gt;', false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('name="sellable_quantity"', false)
            ->assertDontSee(route('admin.orders.return-inspections.complete', [$order->order_code, $item->id]));
    }

    public function test_replay_requires_complete_and_unique_audit_evidence(): void
    {
        [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 2);
        $admin = User::factory()->admin()->create();
        $receiveKey = (string) Str::uuid();
        $receive = app(ReceiveReturnInspection::class);
        $inspection = $receive->handle($order->order_code, $item->id, $admin, $receiveKey, 'Nhận');

        (new AuditLog)->forceFill([
            'actor_id' => $admin->id,
            'action' => 'unrelated.event',
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'before_json' => null,
            'after_json' => null,
            'request_id' => $receiveKey,
            'created_at' => now(),
        ])->save();
        $this->assertValidationKey(fn () => $receive->handle($order->order_code, $item->id, $admin, $receiveKey, 'Nhận'), 'audit');

        [$otherOrder, $otherItem] = $this->orderWithItem(OrderStatus::Placed, 2);
        $otherKey = (string) Str::uuid();
        app(ReceiveReturnInspection::class)->handle($otherOrder->order_code, $otherItem->id, $admin, $otherKey);
        $completeKey = (string) Str::uuid();
        $complete = app(CompleteReturnInspection::class);
        $complete->handle($otherOrder->order_code, $otherItem->id, $admin, $completeKey, 2, 0);
        (new AuditLog)->forceFill([
            'actor_id' => $admin->id,
            'action' => 'unrelated.event',
            'subject_type' => Order::class,
            'subject_id' => $otherOrder->id,
            'before_json' => null,
            'after_json' => null,
            'request_id' => $completeKey,
            'created_at' => now(),
        ])->save();
        $this->assertValidationKey(fn () => $complete->handle($otherOrder->order_code, $otherItem->id, $admin, $completeKey, 2, 0), 'audit');

        $this->assertSame($inspection->id, ReturnInspection::query()->whereKey($inspection->id)->value('id'));
    }

    public function test_historical_pending_without_event_metadata_can_be_completed_but_cannot_fake_receive_replay(): void
    {
        [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 2);
        $admin = User::factory()->admin()->create();
        DB::table('return_inspections')->insert([
            'order_item_id' => $item->id,
            'received_by' => $admin->id,
            'received_at' => now()->subMinute(),
            'inspected_by' => null,
            'inspected_at' => null,
            'sellable_quantity' => null,
            'damaged_quantity' => null,
            'note' => 'Historical',
            'receive_event_key' => null,
            'receive_fingerprint' => null,
            'complete_event_key' => null,
            'complete_fingerprint' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->assertValidationKey(fn () => app(ReceiveReturnInspection::class)->handle(
            $order->order_code, $item->id, $admin, (string) Str::uuid(), 'Historical'
        ), 'event_key');

        $completed = app(CompleteReturnInspection::class)->handle(
            $order->order_code, $item->id, $admin, (string) Str::uuid(), 1, 1, 'Historical'
        );
        $this->assertTrue($completed->isCompleted());
        $this->assertNull($completed->receive_event_key);
        $this->assertNotNull($completed->complete_event_key);
    }

    public function test_missing_or_corrupt_operation_evidence_never_becomes_a_successful_replay(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([false, true] as $corruptFingerprint) {
            [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 2);
            $eventKey = (string) Str::uuid();
            $note = 'Evidence';
            $fingerprint = hash('sha256', json_encode([
                'operation' => 'return_inspection.receive',
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'actor_id' => $admin->id,
                'note' => $note,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->insertPendingInspection($item, $admin, $eventKey, $corruptFingerprint ? str_repeat('0', 64) : $fingerprint, $note);

            $this->assertValidationKey(
                fn () => app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $admin, $eventKey, $note),
                $corruptFingerprint ? 'event_key' : 'audit',
            );
        }

        [$order, $item] = $this->orderWithItem(OrderStatus::Placed, 2);
        $completeKey = (string) Str::uuid();
        $inspectionId = $this->insertPendingInspection($item, $admin, null, null, 'Historical');
        $completeFingerprint = hash('sha256', json_encode([
            'operation' => 'return_inspection.complete',
            'inspection_id' => $inspectionId,
            'order_item_id' => $item->id,
            'actor_id' => $admin->id,
            'sellable_quantity' => 2,
            'damaged_quantity' => 0,
            'note' => 'Historical',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        DB::table('return_inspections')->where('id', $inspectionId)->update([
            'inspected_by' => $admin->id,
            'inspected_at' => now(),
            'sellable_quantity' => 2,
            'damaged_quantity' => 0,
            'complete_event_key' => $completeKey,
            'complete_fingerprint' => $completeFingerprint,
            'updated_at' => now(),
        ]);
        $this->assertValidationKey(
            fn () => app(CompleteReturnInspection::class)->handle($order->order_code, $item->id, $admin, $completeKey, 2, 0, 'Historical'),
            'audit',
        );
    }

    /** @return array{Order, OrderItem} */
    private function orderWithItem(OrderStatus $status, int $quantity = 2, string $name = 'Bàn phím', string $sku = 'KB-001'): array
    {
        $order = Order::factory()->create(['status' => $status]);
        $item = OrderItem::factory()->for($order)->create([
            'product_name' => $name,
            'sku' => $sku,
            'quantity' => $quantity,
            'line_subtotal_vnd' => 100_000 * $quantity,
            'line_total_vnd' => 100_000 * $quantity,
        ]);

        return [$order, $item];
    }

    private function insertPendingInspection(OrderItem $item, User $actor, ?string $eventKey, ?string $fingerprint, ?string $note): int
    {
        return DB::table('return_inspections')->insertGetId([
            'order_item_id' => $item->id,
            'received_by' => $actor->id,
            'received_at' => now()->subMinute(),
            'inspected_by' => null,
            'inspected_at' => null,
            'sellable_quantity' => null,
            'damaged_quantity' => null,
            'note' => $note,
            'receive_event_key' => $eventKey,
            'receive_fingerprint' => $fingerprint,
            'complete_event_key' => null,
            'complete_fingerprint' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }

    private function assertValidationKey(callable $operation, string $key): void
    {
        try {
            $operation();
            $this->fail('Operation unexpectedly succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
