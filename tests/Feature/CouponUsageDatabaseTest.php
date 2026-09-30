<?php

namespace Tests\Feature;

use App\Enums\CouponUsageStatus;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class CouponUsageDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private function reserved(): CouponUsage
    {
        $customer = User::factory()->create();
        $coupon = Coupon::factory()->create();
        $attempt = PaymentAttempt::factory()->create(['user_id' => $customer->id, 'coupon_id' => $coupon->id]);

        return CouponUsage::factory()->create([
            'coupon_id' => $coupon->id, 'customer_id' => $customer->id,
            'payment_attempt_id' => $attempt->id, 'expires_at' => $attempt->expires_at,
        ]);
    }

    public function test_schema_casts_relationships_default_and_indexes_are_present(): void
    {
        $usage = $this->reserved();
        $this->assertTrue(Schema::hasColumns('coupon_usages', [
            'id', 'coupon_id', 'customer_id', 'payment_attempt_id', 'order_id', 'status',
            'reserved_at', 'expires_at', 'consumed_at', 'released_at', 'release_reason',
            'late_callback_exception', 'created_at', 'updated_at',
        ]));
        $this->assertSame(CouponUsageStatus::Reserved, $usage->status);
        $this->assertFalse($usage->late_callback_exception);
        $this->assertSame(['*'], $usage->getGuarded());
        $this->assertTrue($usage->coupon->usages->contains($usage));
        $this->assertTrue($usage->customer->couponUsages->contains($usage));
        $this->assertTrue($usage->paymentAttempt->couponUsage->is($usage));

        $indexes = collect(DB::select('PRAGMA index_list(\'coupon_usages\')'))->pluck('name');
        foreach (['coupon_usages_capacity_index', 'coupon_usages_customer_capacity_index', 'coupon_usages_expiration_index'] as $index) {
            $this->assertContains($index, $indexes);
        }
        $this->assertNotContains('coupon_usages_attempt_status_index', $indexes);
        $sql = DB::selectOne('SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = \'coupon_usages\'')->sql;
        foreach (['coupon_usages_status_check', 'coupon_usages_state_check', 'coupon_usages_time_check', 'coupon_usages_late_callback_check'] as $constraint) {
            $this->assertStringContainsString($constraint, $sql);
        }
    }

    public function test_factory_states_preserve_cross_table_coupon_usage_invariants(): void
    {
        foreach ([
            CouponUsage::factory()->create(),
            CouponUsage::factory()->released()->create(),
            CouponUsage::factory()->consumed()->create(),
            CouponUsage::factory()->lateConsumed()->create(),
        ] as $usage) {
            $this->assertSame($usage->customer_id, $usage->paymentAttempt->user_id);
            $this->assertSame($usage->coupon_id, $usage->paymentAttempt->coupon_id);
            $this->assertSame($usage->coupon_id, $usage->paymentAttempt->pricing_snapshot_json['coupon']['coupon_id']);
            $this->assertTrue($usage->expires_at->equalTo($usage->paymentAttempt->expires_at));

            if ($usage->status === CouponUsageStatus::Consumed) {
                $this->assertSame($usage->customer_id, $usage->order->user_id);
                $this->assertSame($usage->coupon_id, $usage->order->coupon_id);
                $this->assertSame($usage->payment_attempt_id, $usage->order->payment_attempt_id);
                $this->assertSame($usage->coupon_id, $usage->order->coupon_snapshot_json['coupon_id']);
            }
        }
    }

    public function test_unique_foreign_keys_mass_assignment_and_immutability_are_enforced(): void
    {
        $usage = $this->reserved();
        foreach ([['payment_attempt_id' => $usage->payment_attempt_id], ['coupon_id' => 999999], ['customer_id' => 999999]] as $invalid) {
            try {
                CouponUsage::factory()->create($invalid);
                $this->fail('Database accepted a duplicate or invalid foreign key.');
            } catch (QueryException) {
                $this->assertDatabaseCount('coupon_usages', 1);
            }
        }
        try {
            (new CouponUsage)->fill(['status' => 'released', 'late_callback_exception' => true]);
            $this->fail('System fields were mass assignable.');
        } catch (MassAssignmentException) {
            $this->assertTrue(true);
        }
        foreach ([fn () => $usage->delete(), function () use ($usage): void {
            $usage->coupon_id++;
            $usage->save();
        }, function () use ($usage): void {
            $usage->forceFill([
                'status' => CouponUsageStatus::Released,
                'released_at' => $usage->reserved_at->addMinute(),
                'release_reason' => 'bypass attempt',
            ])->save();
        }] as $mutation) {
            try {
                $mutation();
                $this->fail('Immutable usage was mutated.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        foreach ([
            fn () => DB::table('coupon_usages')->where('id', $usage->id)->delete(),
            fn () => DB::table('coupon_usages')->where('id', $usage->id)->update(['status' => 'released']),
        ] as $databaseMutation) {
            try {
                $databaseMutation();
                $this->fail('Database lifecycle guard was bypassed.');
            } catch (QueryException) {
                $this->assertDatabaseHas('coupon_usages', [
                    'id' => $usage->id,
                    'status' => CouponUsageStatus::Reserved->value,
                ]);
            }
        }

        $order = Order::factory()->create();
        $first = $usage->getAttributes();
        unset($first['id']);
        $first = array_merge($first, [
            'payment_attempt_id' => null, 'order_id' => $order->id, 'status' => 'consumed',
            'consumed_at' => $usage->reserved_at->addMinute(), 'released_at' => null,
        ]);
        DB::table('coupon_usages')->insert($first);
        try {
            DB::table('coupon_usages')->insert(array_merge($first, ['coupon_id' => Coupon::factory()->create()->id]));
            $this->fail('Database accepted duplicate Order Coupon Usage.');
        } catch (QueryException) {
            $this->assertDatabaseCount('coupon_usages', 2);
        }
    }

    public function test_database_accepts_four_states_and_rejects_invalid_state_combinations(): void
    {
        $reserved = $this->reserved();
        $base = $reserved->getAttributes();
        unset($base['id']);
        $terminalAt = $reserved->reserved_at->addMinute();
        $lateAttempt = PaymentAttempt::factory()->create([
            'user_id' => $reserved->customer_id,
            'coupon_id' => $reserved->coupon_id,
        ]);
        $invalidAttempt = PaymentAttempt::factory()->create([
            'user_id' => $reserved->customer_id,
            'coupon_id' => $reserved->coupon_id,
        ]);

        foreach ([
            ['payment_attempt_id' => null, 'status' => 'released', 'released_at' => $terminalAt, 'release_reason' => 'expired'],
            ['payment_attempt_id' => null, 'order_id' => Order::factory()->create()->id, 'status' => 'consumed', 'consumed_at' => $terminalAt, 'released_at' => null],
            ['payment_attempt_id' => $lateAttempt->id, 'order_id' => Order::factory()->create()->id, 'status' => 'consumed', 'consumed_at' => $terminalAt, 'released_at' => $terminalAt, 'late_callback_exception' => 1],
        ] as $valid) {
            DB::table('coupon_usages')->insert(array_merge($base, $valid));
        }
        $this->assertDatabaseCount('coupon_usages', 4);

        foreach ([
            ['status' => 'invalid'], ['status' => 'reserved', 'expires_at' => null],
            ['status' => 'reserved', 'payment_attempt_id' => null],
            ['status' => 'reserved', 'payment_attempt_id' => $invalidAttempt->id, 'late_callback_exception' => 1],
            ['status' => 'reserved', 'released_at' => $terminalAt], ['status' => 'released', 'released_at' => null],
            ['status' => 'released', 'released_at' => $terminalAt, 'order_id' => Order::factory()->create()->id],
            ['status' => 'released', 'released_at' => $terminalAt, 'consumed_at' => $terminalAt],
            ['status' => 'consumed', 'order_id' => null, 'consumed_at' => $terminalAt],
            ['status' => 'consumed', 'payment_attempt_id' => $invalidAttempt->id, 'order_id' => Order::factory()->create()->id, 'consumed_at' => $terminalAt, 'late_callback_exception' => 1],
            ['status' => 'consumed', 'order_id' => Order::factory()->create()->id, 'consumed_at' => $terminalAt, 'released_at' => $terminalAt, 'late_callback_exception' => 0],
            ['status' => 'released', 'released_at' => $terminalAt, 'late_callback_exception' => 1],
        ] as $invalid) {
            try {
                DB::table('coupon_usages')->insert(array_merge($base, ['payment_attempt_id' => null, 'order_id' => null], $invalid));
                $this->fail('Database accepted an invalid Coupon Usage state.');
            } catch (QueryException) {
                $this->assertDatabaseCount('coupon_usages', 4);
            }
        }
    }

    public function test_migration_refuses_partial_state_and_down_up_isolated_when_empty(): void
    {
        $migration = require database_path('migrations/2026_09_29_000003_create_coupon_usages_table.php');
        try {
            $migration->up();
            $this->fail('Partial state was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('partial migration state', $exception->getMessage());
        }
        $migration->down();
        $this->assertFalse(Schema::hasTable('coupon_usages'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('coupon_usages'));
        $this->assertTrue(Schema::hasTable('orders'));
    }
}
