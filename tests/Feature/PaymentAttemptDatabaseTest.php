<?php

namespace Tests\Feature;

use App\Actions\ConsumeStockReservations;
use App\Enums\PaymentStatus;
use App\Models\PaymentAttempt;
use App\Models\StockReservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PaymentAttemptDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_factories_casts_and_relationships_are_valid(): void
    {
        $attempt = PaymentAttempt::factory()->create();
        $reservation = StockReservation::factory()->for($attempt)->create();

        $this->assertTrue(Schema::hasColumns('payment_attempts', [
            'id', 'user_id', 'shipping_rate_id', 'request_key', 'gateway_reference',
            'gateway_transaction_id', 'status', 'amount_vnd', 'items_snapshot_json',
            'recipient_snapshot_json', 'pricing_snapshot_json', 'shipping_fee_vnd',
            'coupon_id', 'expires_at', 'verified_at', 'gateway_result_code',
            'late_callback_exception', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('stock_reservations', [
            'id', 'payment_attempt_id', 'product_id', 'quantity', 'expires_at',
            'released_at', 'consumed_at', 'created_at', 'updated_at',
        ]));
        $this->assertSame(PaymentStatus::Unpaid, $attempt->status);
        $this->assertIsArray($attempt->items_snapshot_json);
        $this->assertFalse($attempt->late_callback_exception);
        $this->assertSame(['*'], $attempt->getGuarded());
        $this->assertSame(['*'], $reservation->getGuarded());
        $this->assertTrue($attempt->user->paymentAttempts->contains($attempt));
        $this->assertTrue($attempt->shippingRate->paymentAttempts->contains($attempt));
        $this->assertTrue($attempt->stockReservations->contains($reservation));
        $this->assertTrue($reservation->product->stockReservations->contains($reservation));
        $this->assertTrue($reservation->paymentAttempt->is($attempt));
        $this->assertNull($attempt->coupon);
    }

    public function test_database_enforces_attempt_checks_unique_keys_and_foreign_keys(): void
    {
        $attempt = PaymentAttempt::factory()->create();

        foreach ([
            ['status' => 'unknown'],
            ['amount_vnd' => -1],
            ['shipping_fee_vnd' => -1],
            ['late_callback_exception' => 2],
            ['request_key' => 'short'],
        ] as $invalid) {
            try {
                $candidate = PaymentAttempt::factory()->make();
                DB::table('payment_attempts')->insert(array_merge($candidate->getAttributes(), $invalid));
                $this->fail('Database accepted an invalid Payment Attempt.');
            } catch (QueryException) {
                $this->assertDatabaseCount('payment_attempts', 1);
            }
        }

        foreach ([
            ['user_id' => $attempt->user_id, 'request_key' => $attempt->request_key],
            ['gateway_reference' => $attempt->gateway_reference],
        ] as $duplicate) {
            try {
                PaymentAttempt::factory()->create($duplicate);
                $this->fail('Database accepted a duplicate Payment Attempt key.');
            } catch (QueryException) {
                $this->assertDatabaseCount('payment_attempts', 1);
            }
        }

        $attempt->forceFill(['gateway_transaction_id' => 'VNPAY-UNIQUE'])->save();
        $this->expectException(QueryException::class);
        PaymentAttempt::factory()->create(['gateway_transaction_id' => 'VNPAY-UNIQUE']);
    }

    public function test_database_enforces_reservation_checks_unique_and_restricts_deletes(): void
    {
        $reservation = StockReservation::factory()->create();

        foreach ([
            ['quantity' => 0],
            ['quantity' => -1],
            ['released_at' => now(), 'consumed_at' => now()],
        ] as $invalid) {
            try {
                StockReservation::factory()
                    ->for($reservation->paymentAttempt)
                    ->for($reservation->product)
                    ->create($invalid);
                $this->fail('Database accepted an invalid Stock Reservation.');
            } catch (QueryException) {
                $this->assertDatabaseCount('stock_reservations', 1);
            }
        }

        foreach ([$reservation->paymentAttempt, $reservation->product] as $referenced) {
            try {
                $referenced->delete();
                $this->fail('Database deleted a referenced attempt or product.');
            } catch (QueryException) {
                $this->assertDatabaseHas('stock_reservations', ['id' => $reservation->id]);
            }
        }
    }

    public function test_sqlite_exposes_expected_named_indexes_and_constraints(): void
    {
        $attemptIndexes = collect(DB::select("PRAGMA index_list('payment_attempts')"))->pluck('name');
        $reservationIndexes = collect(DB::select("PRAGMA index_list('stock_reservations')"))->pluck('name');

        foreach (['payment_attempts_user_created_index', 'payment_attempts_status_expires_index'] as $index) {
            $this->assertContains($index, $attemptIndexes);
        }
        foreach (['stock_reservations_product_active_index', 'stock_reservations_expiration_index'] as $index) {
            $this->assertContains($index, $reservationIndexes);
        }

        $attemptSql = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'payment_attempts'")->sql;
        $reservationSql = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'stock_reservations'")->sql;
        $this->assertStringContainsString('payment_attempts_status_check', $attemptSql);
        $this->assertStringContainsString('payment_attempts_user_request_unique', $attemptSql);
        $this->assertStringContainsString('payment_attempts_gateway_reference_unique', $attemptSql);
        $this->assertStringContainsString('payment_attempts_gateway_transaction_unique', $attemptSql);
        $this->assertStringContainsString('payment_attempts_items_json_check', $attemptSql);
        $this->assertStringContainsString('stock_reservations_attempt_product_unique', $reservationSql);
        $this->assertStringContainsString('stock_reservations_quantity_check', $reservationSql);
        $this->assertStringContainsString('stock_reservations_terminal_check', $reservationSql);
    }

    public function test_migrations_refuse_partial_existing_tables_without_deleting_rows(): void
    {
        $attempt = PaymentAttempt::factory()->create();
        $reservation = StockReservation::factory()->for($attempt)->create();
        $attemptMigration = require database_path('migrations/2026_09_28_000000_create_payment_attempts_table.php');
        $reservationMigration = require database_path('migrations/2026_09_28_000001_create_stock_reservations_table.php');

        foreach ([$attemptMigration, $reservationMigration] as $migration) {
            try {
                $migration->up();
                $this->fail('Migration accepted an existing destination table.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('partial migration state', $exception->getMessage());
            }
        }

        $this->assertDatabaseHas('payment_attempts', ['id' => $attempt->id]);
        $this->assertDatabaseHas('stock_reservations', ['id' => $reservation->id]);
    }

    public function test_isolated_down_up_preserves_dependencies_when_new_tables_are_empty(): void
    {
        $attemptMigration = require database_path('migrations/2026_09_28_000000_create_payment_attempts_table.php');
        $reservationMigration = require database_path('migrations/2026_09_28_000001_create_stock_reservations_table.php');

        $reservationMigration->down();
        $attemptMigration->down();
        $this->assertFalse(Schema::hasTable('payment_attempts'));
        $this->assertFalse(Schema::hasTable('stock_reservations'));
        foreach (['users', 'shipping_rates', 'coupons', 'products', 'cart_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $attemptMigration->up();
        $reservationMigration->up();
        $this->assertTrue(Schema::hasTable('payment_attempts'));
        $this->assertTrue(Schema::hasTable('stock_reservations'));
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_order_and_coupon_usage_persistence_exist_while_stock_consume_remains_absent(): void
    {
        $this->assertTrue(Schema::hasTable('coupon_usages'));
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertFalse(class_exists(ConsumeStockReservations::class));
    }
}
