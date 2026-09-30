<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Coupon;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class OrderPersistenceDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_factories_casts_relationships_and_security_are_valid(): void
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create();
        $actor = User::factory()->employee()->create();
        $history = OrderStatusHistory::factory()->for($order)->create(['actor_id' => $actor->id]);
        $ledger = InventoryTransaction::factory()->for($item, 'orderItem')->create();

        $this->assertTrue(Schema::hasColumns('orders', [
            'id', 'user_id', 'payment_attempt_id', 'request_key', 'order_code', 'status',
            'payment_method', 'payment_status', 'recipient_name', 'recipient_email',
            'recipient_phone', 'recipient_address', 'recipient_region', 'coupon_id',
            'coupon_snapshot_json', 'items_subtotal_vnd', 'item_discount_vnd',
            'shipping_fee_vnd', 'shipping_discount_vnd', 'total_vnd', 'delivered_at',
            'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('order_items', [
            'id', 'order_id', 'product_id', 'product_name', 'sku', 'quantity',
            'unit_price_vnd', 'line_subtotal_vnd', 'discount_vnd', 'line_total_vnd',
            'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('order_status_histories', [
            'id', 'order_id', 'from_status', 'to_status', 'actor_id', 'reason',
            'event_key', 'created_at',
        ]));
        $this->assertTrue(Schema::hasColumn('inventory_transactions', 'order_item_id'));
        $this->assertSame(OrderStatus::Placed, $order->status);
        $this->assertSame(PaymentMethod::CashOnDelivery, $order->payment_method);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertIsInt($order->total_vnd);
        $this->assertIsInt($item->quantity);
        $this->assertSame($order->item_discount_vnd + $order->shipping_discount_vnd, $order->total_discount_vnd);
        $this->assertSame(['*'], $order->getGuarded());
        $this->assertSame(['*'], $item->getGuarded());
        $this->assertSame(['*'], $history->getGuarded());
        $this->assertTrue($order->customer->orders->contains($order));
        $this->assertTrue($order->items->contains($item));
        $this->assertTrue($order->statusHistories->contains($history));
        $this->assertTrue($item->product->orderItems->contains($item));
        $this->assertTrue($item->inventoryTransactions->contains($ledger));
        $this->assertTrue($ledger->orderItem->is($item));
        $this->assertTrue($actor->orderStatusHistories->contains($history));
        $this->assertNull($order->paymentAttempt);
        $this->assertNull($order->coupon);
        $this->assertTrue(Schema::hasTable('coupon_usages'));
        $this->assertNull($order->couponUsage);
    }

    public function test_sqlite_schema_exposes_named_constraints_indexes_and_immutable_triggers(): void
    {
        $schemaSql = collect(DB::select(
            'SELECT name, sql FROM sqlite_master WHERE type = ? AND name IN (?, ?, ?)',
            ['table', 'orders', 'order_items', 'order_status_histories'],
        ))->pluck('sql', 'name');

        foreach ([
            'orders' => [
                'orders_order_code_unique',
                'orders_payment_attempt_unique',
                'orders_user_request_unique',
                'orders_status_check',
                'orders_payment_method_check',
                'orders_payment_status_check',
                'orders_payment_source_check',
                'orders_coupon_snapshot_check',
                'orders_total_check',
            ],
            'order_items' => [
                'order_items_order_product_unique',
                'order_items_quantity_check',
                'order_items_subtotal_check',
                'order_items_discount_check',
                'order_items_total_check',
            ],
            'order_status_histories' => [
                'order_status_histories_order_event_unique',
                'order_status_histories_from_status_check',
                'order_status_histories_to_status_check',
                'order_status_histories_edge_check',
                'order_status_histories_event_key_check',
            ],
        ] as $table => $constraints) {
            foreach ($constraints as $constraint) {
                $this->assertStringContainsString($constraint, $schemaSql[$table]);
            }
        }

        foreach ([
            'orders' => [
                'orders_user_created_index',
                'orders_status_created_index',
                'orders_payment_status_created_index',
                'orders_payment_method_created_index',
                'orders_coupon_id_index',
            ],
            'order_items' => [
                'order_items_product_order_index',
            ],
            'order_status_histories' => [
                'order_status_histories_order_created_index',
                'order_status_histories_actor_id_index',
            ],
            'inventory_transactions' => [
                'inventory_transactions_order_item_id_index',
            ],
        ] as $table => $indexes) {
            $actual = collect(DB::select('PRAGMA index_list('.$table.')'))->pluck('name');

            foreach ($indexes as $index) {
                $this->assertTrue($actual->contains($index), 'Missing '.$index.' on '.$table.'.');
            }
        }

        $triggers = collect(DB::select(
            'SELECT name FROM sqlite_master WHERE type = ?',
            ['trigger'],
        ))->pluck('name');

        foreach ([
            'inventory_transactions_delete_guard',
            'inventory_transactions_update_guard',
            'order_items_update_guard',
            'order_items_delete_guard',
            'order_status_histories_update_guard',
            'order_status_histories_delete_guard',
        ] as $trigger) {
            $this->assertTrue($triggers->contains($trigger), 'Missing '.$trigger.'.');
        }
    }

    public function test_database_rejects_invalid_order_domains_money_json_and_payment_source(): void
    {
        $valid = Order::factory()->make()->getAttributes();

        foreach ([
            ['status' => 'pending'],
            ['payment_method' => 'cash'],
            ['payment_status' => 'completed'],
            ['items_subtotal_vnd' => -1],
            ['item_discount_vnd' => $valid['items_subtotal_vnd'] + 1],
            ['shipping_discount_vnd' => $valid['shipping_fee_vnd'] + 1],
            ['total_vnd' => $valid['total_vnd'] + 1],
            ['coupon_snapshot_json' => '{invalid-json'],
            ['request_key' => null],
            ['payment_method' => 'vnpay', 'request_key' => null, 'payment_attempt_id' => null],
        ] as $invalid) {
            try {
                DB::table('orders')->insert(array_merge($valid, $invalid, [
                    'order_code' => 'ORD-'.strtoupper(Str::random(24)),
                    'request_key' => array_key_exists('request_key', $invalid)
                        ? $invalid['request_key']
                        : (string) Str::uuid(),
                ]));
                $this->fail('Database accepted an invalid Order.');
            } catch (QueryException) {
                $this->assertDatabaseCount('orders', 0);
            }
        }
    }

    public function test_order_unique_keys_foreign_keys_and_payment_attempt_cardinality_are_enforced(): void
    {
        $cod = Order::factory()->create();

        foreach ([
            ['order_code' => $cod->order_code],
            ['user_id' => $cod->user_id, 'request_key' => $cod->request_key],
        ] as $duplicate) {
            try {
                Order::factory()->create($duplicate);
                $this->fail('Database accepted a duplicate Order identity.');
            } catch (QueryException) {
                $this->assertDatabaseCount('orders', 1);
            }
        }

        try {
            $cod->customer->delete();
            $this->fail('Database deleted an Order customer.');
        } catch (QueryException) {
            $this->assertDatabaseHas('orders', ['id' => $cod->id]);
        }

        $attempt = $this->verifiedAttempt();
        $paid = Order::factory()->forVerifiedAttempt($attempt)->create();
        $this->assertTrue($attempt->fresh()->order->is($paid));

        try {
            $attributes = $paid->getAttributes();
            $attributes['id'] = null;
            $attributes['order_code'] = 'ORD-'.strtoupper(Str::random(24));
            $attributes['created_at'] = now();
            $attributes['updated_at'] = now();
            DB::table('orders')->insert($attributes);
            $this->fail('One Payment Attempt was linked to two Orders.');
        } catch (QueryException) {
            $this->assertDatabaseCount('orders', 2);
        }
    }

    public function test_cod_and_verified_vnpay_application_invariants_are_explicit(): void
    {
        $cod = Order::factory()->create();
        $this->assertNull($cod->payment_attempt_id);
        $this->assertNotNull($cod->request_key);

        $attempt = $this->verifiedAttempt();
        $vnpay = Order::factory()->forVerifiedAttempt($attempt)->create();
        $this->assertSame(PaymentMethod::VnPay, $vnpay->payment_method);
        $this->assertSame(PaymentStatus::Paid, $vnpay->payment_status);
        $this->assertTrue($vnpay->paymentAttempt->is($attempt));

        $unverified = PaymentAttempt::factory()->create();
        foreach ([
            fn () => Order::factory()->forVerifiedAttempt($unverified)->create(),
            fn () => Order::factory()->forVerifiedAttempt($attempt)->create([
                'user_id' => User::factory(),
            ]),
            fn () => Order::factory()->forVerifiedAttempt($this->verifiedAttempt([
                'amount_vnd' => 1,
            ]))->create(),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Invalid VNPay Order relationship was accepted.');
            } catch (LogicException) {
                $this->assertDatabaseCount('orders', 2);
            }
        }
    }

    public function test_cod_request_key_is_customer_scoped_and_strictly_validated(): void
    {
        $requestKey = (string) Str::uuid();
        $firstCustomer = User::factory()->create();
        $secondCustomer = User::factory()->create();

        Order::factory()->for($firstCustomer, 'customer')->create(['request_key' => $requestKey]);
        Order::factory()->for($secondCustomer, 'customer')->create(['request_key' => $requestKey]);
        $this->assertDatabaseCount('orders', 2);

        foreach (['', 123] as $invalidKey) {
            try {
                Order::factory()->create(['request_key' => $invalidKey]);
                $this->fail('Invalid COD request key was accepted.');
            } catch (LogicException) {
                $this->assertDatabaseCount('orders', 2);
            }
        }
    }

    public function test_vnpay_items_and_shipping_reconcile_with_canonical_attempt_snapshots(): void
    {
        $product = Product::factory()->create([
            'name' => 'Snapshot keyboard',
            'sku' => 'SNAPSHOT-KEYBOARD',
        ]);
        $attempt = $this->verifiedAttemptForProduct($product);
        $order = Order::factory()->forVerifiedAttempt($attempt)->create();

        OrderItem::factory()->for($order)->for($product)->create([
            'product_name' => 'Snapshot keyboard',
            'sku' => 'SNAPSHOT-KEYBOARD',
            'quantity' => 2,
            'unit_price_vnd' => 100_000,
            'line_subtotal_vnd' => 200_000,
            'discount_vnd' => 0,
            'line_total_vnd' => 200_000,
        ]);

        $order->assertItemsReconcile();
        $this->assertTrue(true);

        $mismatchedProduct = Product::factory()->create([
            'name' => 'Snapshot mouse',
            'sku' => 'SNAPSHOT-MOUSE',
        ]);
        $mismatchedAttempt = $this->verifiedAttemptForProduct($mismatchedProduct);
        $mismatchedOrder = Order::factory()->forVerifiedAttempt($mismatchedAttempt)->create();
        OrderItem::factory()->for($mismatchedOrder)->for($mismatchedProduct)->create([
            'product_name' => 'Tampered mouse',
            'sku' => 'SNAPSHOT-MOUSE',
            'quantity' => 2,
            'unit_price_vnd' => 100_000,
            'line_subtotal_vnd' => 200_000,
            'discount_vnd' => 0,
            'line_total_vnd' => 200_000,
        ]);

        $this->expectException(LogicException::class);
        $mismatchedOrder->assertItemsReconcile();
    }

    public function test_order_and_item_snapshots_remain_independent_and_reconcile(): void
    {
        $customer = User::factory()->create(['name' => 'Original customer']);
        $coupon = Coupon::factory()->create(['code' => 'ORIGINAL10']);
        $couponSnapshot = [
            'coupon_id' => $coupon->id,
            'code' => 'ORIGINAL10',
            'type' => 'percent',
            'scope' => 'cart',
            'value' => 10,
            'eligible_subtotal_vnd' => 200_000,
        ];
        $order = Order::factory()->for($customer, 'customer')->create([
            'recipient_name' => 'Independent receiver',
            'coupon_id' => $coupon->id,
            'coupon_snapshot_json' => $couponSnapshot,
            'items_subtotal_vnd' => 200_000,
            'item_discount_vnd' => 20_000,
            'shipping_fee_vnd' => 30_000,
            'shipping_discount_vnd' => 0,
            'total_vnd' => 210_000,
        ]);
        $product = Product::factory()->create(['name' => 'Original product', 'sku' => 'ORIGINAL-SKU']);
        $item = OrderItem::factory()->for($order)->for($product)->create([
            'product_name' => 'Original product',
            'sku' => 'ORIGINAL-SKU',
            'quantity' => 2,
            'unit_price_vnd' => 100_000,
            'line_subtotal_vnd' => 200_000,
            'discount_vnd' => 20_000,
            'line_total_vnd' => 180_000,
        ]);

        $order->assertItemsReconcile();
        $customer->update(['name' => 'Changed customer']);
        $coupon->update(['code' => 'CHANGED10']);
        $product->update(['name' => 'Changed product', 'sku' => 'CHANGED-SKU']);

        $this->assertSame('Independent receiver', $order->fresh()->recipient_name);
        $this->assertSame('ORIGINAL10', $order->fresh()->coupon_snapshot_json['code']);
        $this->assertSame('Original product', $item->fresh()->product_name);
        $this->assertSame('ORIGINAL-SKU', $item->fresh()->sku);
        $this->assertSame(210_000, $order->fresh()->total_vnd);
    }

    public function test_invalid_snapshot_shape_and_field_injection_fail_safely(): void
    {
        $this->assertSame(['*'], (new Order)->getGuarded());

        try {
            Order::factory()->create([
                'coupon_id' => Coupon::factory(),
                'coupon_snapshot_json' => ['password' => 'secret'],
            ]);
            $this->fail('Invalid coupon snapshot shape was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('orders', 0);
        }

        try {
            (new Order)->fill([
                'user_id' => 999,
                'status' => OrderStatus::Delivered,
                'payment_status' => PaymentStatus::Paid,
                'total_vnd' => 1,
            ]);
            $this->fail('System-owned Order fields were mass assignable.');
        } catch (MassAssignmentException) {
            $this->assertTrue(true);
        }
    }

    public function test_order_item_checks_unique_product_and_product_fk_are_enforced(): void
    {
        $item = OrderItem::factory()->create();
        $valid = OrderItem::factory()->for($item->order)->make()->getAttributes();

        foreach ([
            ['quantity' => 0],
            ['quantity' => -1],
            ['line_subtotal_vnd' => $valid['line_subtotal_vnd'] + 1],
            ['discount_vnd' => $valid['line_subtotal_vnd'] + 1],
            ['line_total_vnd' => $valid['line_total_vnd'] + 1],
        ] as $invalid) {
            try {
                DB::table('order_items')->insert(array_merge($valid, $invalid, [
                    'product_id' => Product::factory()->create()->id,
                ]));
                $this->fail('Database accepted an invalid Order Item.');
            } catch (QueryException) {
                $this->assertDatabaseCount('order_items', 1);
            }
        }

        try {
            OrderItem::factory()->for($item->order)->for($item->product)->create();
            $this->fail('Database accepted a duplicate Product line.');
        } catch (QueryException) {
            $this->assertDatabaseCount('order_items', 1);
        }

        try {
            $item->product->delete();
            $this->fail('Database deleted a Product referenced by an Order Item.');
        } catch (QueryException) {
            $this->assertDatabaseHas('order_items', ['id' => $item->id]);
        }
    }

    public function test_history_accepts_only_lifecycle_edges_is_stably_ordered_and_append_only(): void
    {
        $order = Order::factory()->create();
        $actor = User::factory()->employee()->create();
        $initial = OrderStatusHistory::factory()->for($order)->create([
            'actor_id' => $actor->id,
            'created_at' => '2026-09-29 01:00:00.100000',
        ]);
        $handoff = OrderStatusHistory::factory()->for($order)->create([
            'from_status' => OrderStatus::Placed,
            'to_status' => OrderStatus::AwaitingHandoff,
            'created_at' => '2026-09-29 01:00:00.100000',
        ]);

        $this->assertSame([$initial->id, $handoff->id], $order->statusHistories->pluck('id')->all());

        try {
            OrderStatusHistory::factory()->for($order)->create(['event_key' => $initial->event_key]);
            $this->fail('Database accepted a duplicate Order event key.');
        } catch (QueryException) {
            $this->assertDatabaseCount('order_status_histories', 2);
        }

        foreach ([
            ['from_status' => null, 'to_status' => 'da_giao'],
            ['from_status' => 'unknown', 'to_status' => 'da_dat'],
            ['from_status' => 'da_giao', 'to_status' => 'da_dat'],
        ] as $invalid) {
            try {
                $attributes = OrderStatusHistory::factory()->for($order)->make()->getAttributes();
                DB::table('order_status_histories')->insert(array_merge($attributes, $invalid, [
                    'event_key' => (string) Str::uuid(),
                ]));
                $this->fail('Database accepted an invalid Order status edge.');
            } catch (QueryException) {
                $this->assertDatabaseCount('order_status_histories', 2);
            }
        }

        foreach ([
            fn () => DB::table('order_status_histories')->where('id', $initial->id)->update(['reason' => 'changed']),
            fn () => DB::table('order_status_histories')->where('id', $initial->id)->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Database allowed mutation of append-only history.');
            } catch (QueryException) {
                $this->assertDatabaseHas('order_status_histories', ['id' => $initial->id]);
            }
        }

        $actor->delete();
        $this->assertNull($initial->fresh()->actor_id);
    }

    public function test_history_model_and_order_item_model_block_update_and_delete(): void
    {
        $history = OrderStatusHistory::factory()->create();
        $item = OrderItem::factory()->create();

        foreach ([
            function () use ($history) {
                $history->reason = 'changed';
                $history->save();
            },
            fn () => $history->delete(),
            function () use ($item) {
                $item->quantity++;
                $item->save();
            },
            fn () => $item->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Immutable Order persistence model was mutated.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }

        foreach ([
            fn () => DB::table('order_items')->where('id', $item->id)->update(['product_name' => 'changed']),
            fn () => DB::table('order_items')->where('id', $item->id)->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Database allowed mutation of an immutable Order Item.');
            } catch (QueryException) {
                $this->assertDatabaseHas('order_items', ['id' => $item->id]);
            }
        }
    }

    public function test_partial_guards_and_isolated_down_up_preserve_existing_dependencies(): void
    {
        $orderMigration = require database_path('migrations/2026_09_29_000000_create_orders_table.php');
        $itemMigration = require database_path('migrations/2026_09_29_000001_create_order_items_table.php');
        $historyMigration = require database_path('migrations/2026_09_29_000002_create_order_status_histories_table.php');
        $usageMigration = require database_path('migrations/2026_09_29_000003_create_coupon_usages_table.php');

        foreach ([$orderMigration, $itemMigration, $historyMigration, $usageMigration] as $migration) {
            try {
                $migration->up();
                $this->fail('Migration accepted a partial existing state.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('partial migration state', $exception->getMessage());
            }
        }

        $usageMigration->down();
        $historyMigration->down();
        $itemMigration->down();
        $orderMigration->down();
        $this->assertFalse(Schema::hasTable('orders'));
        $this->assertFalse(Schema::hasTable('order_items'));
        $this->assertFalse(Schema::hasTable('order_status_histories'));
        $this->assertFalse(Schema::hasColumn('inventory_transactions', 'order_item_id'));
        foreach (['users', 'products', 'payment_attempts', 'inventory_transactions'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $orderMigration->up();
        $itemMigration->up();
        $historyMigration->up();
        $usageMigration->up();
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertTrue(Schema::hasTable('order_items'));
        $this->assertTrue(Schema::hasTable('order_status_histories'));
        $this->assertTrue(Schema::hasTable('coupon_usages'));
        $this->assertTrue(Schema::hasColumn('inventory_transactions', 'order_item_id'));
    }

    public function test_no_order_route_ui_or_order_writer_is_added(): void
    {
        $this->assertTrue(Schema::hasTable('coupon_usages'));
        $this->assertFalse(class_exists(CreateOrder::class));
        $this->assertFalse(collect(app('router')->getRoutes())->contains(
            fn ($route) => str_contains((string) $route->getName(), 'order')
                || str_contains($route->uri(), 'order'),
        ));
    }

    /** @param array<string, mixed> $attributes */
    private function verifiedAttempt(array $attributes = []): PaymentAttempt
    {
        $attempt = PaymentAttempt::factory()->create($attributes);
        $attempt->forceFill([
            'status' => PaymentStatus::Paid,
            'verified_at' => now(),
        ])->save();

        return $attempt->fresh();
    }

    private function verifiedAttemptForProduct(Product $product): PaymentAttempt
    {
        $shippingRate = ShippingRate::query()->orderBy('id')->firstOrFail();
        $shippingFee = $shippingRate->fee_vnd;
        $subtotal = 200_000;

        return $this->verifiedAttempt([
            'amount_vnd' => $subtotal + $shippingFee,
            'shipping_rate_id' => $shippingRate->id,
            'shipping_fee_vnd' => $shippingFee,
            'items_snapshot_json' => [[
                'sku' => $product->sku,
                'quantity' => 2,
                'brand_id' => $product->brand_id,
                'product_name' => $product->name,
                'product_id' => $product->id,
                'line_subtotal_vnd' => $subtotal,
                'category_id' => $product->category_id,
                'unit_price_vnd' => 100_000,
            ]],
            'pricing_snapshot_json' => [
                'shipping' => [
                    'region_label' => $shippingRate->region_key->label(),
                    'shipping_fee_vnd' => $shippingFee,
                    'shipping_rate_id' => $shippingRate->id,
                    'region_key' => $shippingRate->region_key->value,
                ],
                'total_vnd' => $subtotal + $shippingFee,
                'total_discount_vnd' => 0,
                'shipping_fee_vnd' => $shippingFee,
                'item_discount_vnd' => 0,
                'cart_subtotal_vnd' => $subtotal,
                'shipping_discount_vnd' => 0,
                'shipping_fee_after_discount_vnd' => $shippingFee,
            ],
        ]);
    }
}
