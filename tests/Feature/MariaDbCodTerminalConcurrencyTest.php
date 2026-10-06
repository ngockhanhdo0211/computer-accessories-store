<?php

namespace Tests\Feature;

use App\Actions\CancelCodOrder;
use App\Actions\CompleteReturnInspection;
use App\Actions\ReceiveReturnInspection;
use App\Enums\CouponUsageStatus;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderStatus;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbCodTerminalConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('RUN_MARIADB_COD_TERMINAL_QA') !== '1') {
            $this->markTestSkipped('Run with the isolated MariaDB COD terminal QA runner.');
        }
        if (DB::getDriverName() !== 'mysql' || ! str_contains((string) DB::getDatabaseName(), '_cod_terminal_qa_')) {
            $this->fail('COD terminal concurrency QA requires the isolated marker database.');
        }
    }

    public function test_cancellation_replay_competition_coupon_and_multi_item_are_exactly_once(): void
    {
        $admin = User::factory()->admin()->create();
        [$replay, $items] = $this->order(OrderStatus::InTransit, [2, 3], null, true);
        $this->inspect($replay, $items, $admin, [[1, 1], [0, 3]]);
        $key = (string) Str::uuid();
        $payload = $this->payload('cancel', $replay, $admin, $key, 'MariaDB replay');
        $this->assertSame([0, 0], $this->codes($this->runTwo($payload, $payload)));
        $this->assertSame(OrderStatus::Cancelled, $replay->refresh()->status);
        $this->assertSame(2, InventoryTransaction::query()->where('type', InventoryTransactionType::CancelRestore)->whereIn('order_item_id', $items->pluck('id'))->count());
        $this->assertSame(CouponUsageStatus::Released, $replay->couponUsage()->firstOrFail()->status);
        $this->assertSame(1, OrderStatusHistory::query()->where('event_key', $key)->count());
        $this->assertSame(1, AuditLog::query()->where('request_id', $key)->count());

        [$competing, $competingItems] = $this->order(OrderStatus::InTransit, [1]);
        $this->inspect($competing, $competingItems, $admin, [[1, 0]]);
        $this->assertSame([0, 2], $this->codes($this->runTwo(
            $this->payload('cancel', $competing, $admin, (string) Str::uuid(), 'A'),
            $this->payload('cancel', $competing, $admin, (string) Str::uuid(), 'B'),
        )));
        $this->assertSame(1, InventoryTransaction::query()->where('type', InventoryTransactionType::CancelRestore)->where('order_item_id', $competingItems->first()->id)->count());
    }

    public function test_cancel_and_deliver_compete_with_single_terminal_winner(): void
    {
        $admin = User::factory()->admin()->create();
        [$order, $items, $products] = $this->order(OrderStatus::InTransit, [2]);
        $this->inspect($order, $items, $admin, [[1, 1]]);
        $before = $products->first()->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity']);
        $this->assertSame([0, 2], $this->codes($this->runTwo(
            $this->payload('cancel', $order, $admin, (string) Str::uuid(), 'Cancel race'),
            $this->payload('deliver', $order, $admin, (string) Str::uuid(), 'Deliver race'),
        )));

        $fresh = $order->fresh();
        $product = $products->first()->fresh();
        $this->assertContains($fresh->status, [OrderStatus::Cancelled, OrderStatus::Delivered]);
        if ($fresh->status === OrderStatus::Cancelled) {
            $this->assertSame($before['sold_quantity'], $product->sold_quantity);
            $this->assertSame($before['sellable_quantity'] + 1, $product->sellable_quantity);
            $this->assertSame($before['damaged_quantity'] + 1, $product->damaged_quantity);
        } else {
            $this->assertSame($before['sold_quantity'] + 2, $product->sold_quantity);
            $this->assertSame($before['sellable_quantity'], $product->sellable_quantity);
            $this->assertSame($before['damaged_quantity'], $product->damaged_quantity);
        }
    }

    public function test_same_event_key_on_two_orders_has_one_global_winner(): void
    {
        $firstActor = User::factory()->employee()->create();
        $secondActor = User::factory()->employee()->create();
        [$first] = $this->order(OrderStatus::InTransit, [1]);
        [$second] = $this->order(OrderStatus::InTransit, [1]);
        $key = (string) Str::uuid();

        $this->assertSame([0, 2], $this->codes($this->runTwo(
            $this->payload('deliver', $first, $firstActor, $key, 'Global event'),
            $this->payload('deliver', $second, $secondActor, $key, 'Global event'),
        )));
        $this->assertSame(1, OrderStatusHistory::query()->where('event_key', $key)->count());
        $this->assertSame(1, AuditLog::query()->where('request_id', $key)->count());
        $this->assertSame(1, Order::query()->whereIn('id', [$first->id, $second->id])->where('status', OrderStatus::Delivered)->count());
        $this->assertSame(1, Order::query()->whereIn('id', [$first->id, $second->id])->where('status', OrderStatus::InTransit)->count());
    }

    public function test_competing_deliveries_and_two_orders_for_one_customer_do_not_lose_membership_updates(): void
    {
        $employee = User::factory()->employee()->create();
        [$replay, , $replayProducts] = $this->order(OrderStatus::InTransit, [2]);
        $replayKey = (string) Str::uuid();
        $replayPayload = $this->payload('deliver', $replay, $employee, $replayKey, 'Same delivery');
        $this->assertSame([0, 0], $this->codes($this->runTwo($replayPayload, $replayPayload)));
        $this->assertSame(6, $replayProducts->first()->fresh()->sold_quantity);
        $this->assertSame(1, OrderStatusHistory::query()->where('event_key', $replayKey)->count());
        $this->assertSame(1, AuditLog::query()->where('request_id', $replayKey)->count());

        [$single, , $singleProducts] = $this->order(OrderStatus::InTransit, [2]);
        $this->assertSame([0, 2], $this->codes($this->runTwo(
            $this->payload('deliver', $single, $employee, (string) Str::uuid(), 'One'),
            $this->payload('deliver', $single, $employee, (string) Str::uuid(), 'Two'),
        )));
        $this->assertSame(6, $singleProducts->first()->fresh()->sold_quantity);

        $customer = User::factory()->create();
        $sharedProduct = Product::factory()->create(['sellable_quantity' => 5, 'damaged_quantity' => 1, 'sold_quantity' => 4]);
        [$first] = $this->order(OrderStatus::InTransit, [1], $customer, false, 3_000_000, $sharedProduct);
        [$second] = $this->order(OrderStatus::InTransit, [1], $customer, false, 4_000_000, $sharedProduct);
        $this->assertSame([0, 0], $this->codes($this->runTwo(
            $this->payload('deliver', $first, $employee, (string) Str::uuid(), 'First order'),
            $this->payload('deliver', $second, $employee, (string) Str::uuid(), 'Second order'),
        )));
        $this->assertSame(7_000_000, $customer->fresh()->membership_spending);
        $this->assertSame(6, $sharedProduct->fresh()->sold_quantity);
    }

    public function test_mariadb_metadata_unique_boundary_and_coupon_direct_sql_guards(): void
    {
        $meta = DB::selectOne('SELECT VERSION() AS version, @@innodb_force_recovery AS recovery');
        $this->assertStringContainsString('10.4.32-MariaDB', $meta->version);
        $this->assertSame(0, (int) $meta->recovery);
        foreach (DB::select('CHECK TABLE inventory_transactions, coupon_usages, orders, order_status_histories') as $check) {
            $this->assertSame('OK', $check->Msg_text);
        }
        $this->assertSame(2, DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'inventory_transactions')
            ->where('INDEX_NAME', 'inventory_transactions_type_order_item_unique')->count());
        $this->assertSame(1, DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'order_status_histories')
            ->where('INDEX_NAME', 'order_status_histories_event_unique')->count());
        $this->assertSame(1, DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', 'inventory_transactions_terminal_insert_check')->count());

        [$order, $items] = $this->order(OrderStatus::Placed, [1], null, true);
        $usage = $order->couponUsage()->firstOrFail();
        try {
            DB::table('coupon_usages')->where('id', $usage->id)->update([
                'status' => 'released', 'released_at' => now(), 'release_reason' => 'invalid-direct-release', 'updated_at' => now(),
            ]);
            $this->fail('MariaDB released a Coupon Usage before a valid COD cancellation.');
        } catch (QueryException) {
            $this->assertSame(CouponUsageStatus::Consumed, $usage->fresh()->status);
        }
        $admin = User::factory()->admin()->create();
        $this->inspect($order, $items, $admin, [[1, 0]]);
        app(CancelCodOrder::class)->handle($order->order_code, $admin, (string) Str::uuid(), 'Valid MariaDB cancellation');
        $this->assertSame(CouponUsageStatus::Released, $usage->fresh()->status);

        $vnpayUsage = CouponUsage::factory()->consumed()->create();
        try {
            DB::table('coupon_usages')->where('id', $vnpayUsage->id)->update([
                'status' => 'released', 'released_at' => now(), 'release_reason' => 'invalid-vnpay-release', 'updated_at' => now(),
            ]);
            $this->fail('Terminal migration must not release a consumed VNPay usage.');
        } catch (QueryException) {
            $this->assertSame(CouponUsageStatus::Consumed, $vnpayUsage->fresh()->status);
        }

        [$boundaryOrder, $boundaryItems, $boundaryProducts] = $this->order(OrderStatus::Placed, [1]);
        $this->inspect($boundaryOrder, $boundaryItems, $admin, [[1, 0]]);
        $boundaryInspection = $boundaryItems->first()->returnInspection()->firstOrFail();
        $base = [
            'product_id' => $boundaryProducts->first()->id, 'type' => 'cancel_restore',
            'sellable_delta' => 1, 'damaged_delta' => 0, 'adjustment_request_id' => null,
            'order_item_id' => $boundaryItems->first()->id, 'return_inspection_id' => $boundaryInspection->id,
            'actor_id' => null, 'reason' => null, 'created_at' => now(),
        ];
        try {
            DB::table('inventory_transactions')->insert([
                ...$base, 'return_inspection_id' => null, 'source_key' => 'maria-invalid-restore',
            ]);
            $this->fail('MariaDB accepted a cancel_restore without its Return Inspection.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('inventory_transactions', ['source_key' => 'maria-invalid-restore']);
        }
        DB::table('inventory_transactions')->insert([...$base, 'source_key' => 'maria-restore-one']);
        try {
            DB::table('inventory_transactions')->insert([...$base, 'source_key' => 'maria-restore-two']);
            $this->fail('MariaDB must reject a second cancel_restore for one Order Item.');
        } catch (QueryException) {
            $this->assertSame(1, InventoryTransaction::query()->where('type', InventoryTransactionType::CancelRestore)->where('order_item_id', $boundaryItems->first()->id)->count());
        }
        $ledger = InventoryTransaction::query()->where('source_key', 'maria-restore-one')->firstOrFail();
        foreach ([
            fn () => DB::table('inventory_transactions')->where('id', $ledger->id)->update(['reason' => 'changed']),
            fn () => DB::table('inventory_transactions')->where('id', $ledger->id)->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('MariaDB mutated an immutable terminal ledger.');
            } catch (QueryException) {
                $this->assertDatabaseHas('inventory_transactions', ['id' => $ledger->id]);
            }
        }

        foreach ([1, 2] as $suffix) {
            DB::table('inventory_transactions')->insert([
                'product_id' => $boundaryProducts->first()->id, 'type' => 'import',
                'sellable_delta' => 1, 'damaged_delta' => 0, 'source_key' => 'maria-null-item-'.$suffix,
                'adjustment_request_id' => null, 'order_item_id' => null, 'return_inspection_id' => null,
                'actor_id' => null, 'reason' => null, 'created_at' => now(),
            ]);
        }
        $this->assertSame(2, InventoryTransaction::query()->whereNull('order_item_id')->where('source_key', 'like', 'maria-null-item-%')->count());
    }

    /** @return array<string, int|string> */
    private function payload(string $operation, Order $order, User $actor, string $eventKey, string $reason): array
    {
        return ['operation' => $operation, 'order_id' => $order->id, 'actor_id' => $actor->id, 'event_key' => $eventKey, 'reason' => $reason];
    }

    private function workerCode(): string
    {
        return <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$deadline = microtime(true) + 10;
while (! file_exists($argv[2]) && microtime(true) < $deadline) { usleep(10000); }
if (! file_exists($argv[2])) { fwrite(STDERR, 'barrier timeout'); exit(4); }
try {
    $order = App\Models\Order::query()->findOrFail($payload['order_id']);
    $actor = App\Models\User::query()->findOrFail($payload['actor_id']);
    $action = $payload['operation'] === 'cancel'
        ? app(App\Actions\CancelCodOrder::class)
        : app(App\Actions\DeliverCodOrder::class);
    $action->handle($order->order_code, $actor, $payload['event_key'], $payload['reason']);
    exit(0);
} catch (Illuminate\Validation\ValidationException $exception) {
    fwrite(STDERR, 'domain rejection'); exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()); exit(3);
}
PHP;
    }

    /** @return list<Process> */
    private function runTwo(array $first, array $second): array
    {
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cod-terminal-'.Str::uuid();
        $worker = $this->workerCode();
        $processes = array_map(function (array $payload) use ($worker, $barrier): Process {
            $process = new Process([PHP_BINARY, '-r', $worker, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $barrier], base_path());
            $process->setTimeout(30);
            $process->start();

            return $process;
        }, [$first, $second]);

        try {
            usleep(100000);
            file_put_contents($barrier, 'go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertNotSame(4, $process->getExitCode(), $process->getErrorOutput());
                $this->assertNotSame(3, $process->getExitCode(), $process->getErrorOutput());
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if (file_exists($barrier)) {
                unlink($barrier);
            }
        }

        return $processes;
    }

    private function codes(array $processes): array
    {
        $codes = array_map(fn (Process $process): int => $process->getExitCode() ?? -1, $processes);
        sort($codes);

        return $codes;
    }

    /** @return array{Order, Collection<int, OrderItem>, Collection<int, Product>} */
    private function order(
        OrderStatus $status,
        array $quantities,
        ?User $customer = null,
        bool $coupon = false,
        int $price = 100_000,
        ?Product $sharedProduct = null,
    ): array {
        $customer ??= User::factory()->create();
        $subtotal = array_sum($quantities) * $price;
        $couponModel = $coupon ? Coupon::factory()->create() : null;
        $order = Order::factory()->for($customer, 'customer')->create([
            'status' => $status, 'coupon_id' => $couponModel?->id,
            'coupon_snapshot_json' => $couponModel === null ? null : [
                'coupon_id' => $couponModel->id, 'code' => $couponModel->code, 'type' => $couponModel->type->value,
                'scope' => $couponModel->scope->value, 'value' => $couponModel->value, 'eligible_subtotal_vnd' => $subtotal,
            ],
            'items_subtotal_vnd' => $subtotal, 'item_discount_vnd' => 0,
            'shipping_fee_vnd' => 0, 'shipping_discount_vnd' => 0, 'total_vnd' => $subtotal,
        ]);
        $items = collect();
        $products = collect();
        foreach ($quantities as $index => $quantity) {
            $product = $sharedProduct ?? Product::factory()->create(['sku' => 'MARIA-TERMINAL-'.$order->id.'-'.$index, 'sellable_quantity' => 5, 'damaged_quantity' => 1, 'sold_quantity' => 4]);
            $items->push(OrderItem::factory()->for($order)->for($product)->create([
                'quantity' => $quantity, 'unit_price_vnd' => $price, 'line_subtotal_vnd' => $quantity * $price,
                'discount_vnd' => 0, 'line_total_vnd' => $quantity * $price,
            ]));
            $products->push($product);
        }
        $edges = [[null, OrderStatus::Placed]];
        if (in_array($status, [OrderStatus::AwaitingHandoff, OrderStatus::InTransit], true)) {
            $edges[] = [OrderStatus::Placed, OrderStatus::AwaitingHandoff];
        }
        if ($status === OrderStatus::InTransit) {
            $edges[] = [OrderStatus::AwaitingHandoff, OrderStatus::InTransit];
        }
        foreach ($edges as $index => [$from, $to]) {
            (new OrderStatusHistory)->forceFill([
                'order_id' => $order->id, 'from_status' => $from, 'to_status' => $to, 'actor_id' => null,
                'reason' => null, 'event_key' => (string) Str::uuid(), 'created_at' => now()->addMicroseconds($index),
            ])->save();
        }
        if ($couponModel !== null) {
            (new CouponUsage)->forceFill([
                'coupon_id' => $couponModel->id, 'customer_id' => $customer->id, 'payment_attempt_id' => null,
                'order_id' => $order->id, 'status' => CouponUsageStatus::Consumed, 'reserved_at' => null,
                'expires_at' => null, 'consumed_at' => now(), 'released_at' => null, 'release_reason' => null,
                'late_callback_exception' => false, 'created_at' => now(), 'updated_at' => now(),
            ])->save();
        }

        return [$order, $items, $products];
    }

    private function inspect(Order $order, $items, User $actor, array $splits): void
    {
        foreach ($items->values() as $index => $item) {
            app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $actor, (string) Str::uuid());
            app(CompleteReturnInspection::class)->handle($order->order_code, $item->id, $actor, (string) Str::uuid(), $splits[$index][0], $splits[$index][1]);
        }
    }
}
