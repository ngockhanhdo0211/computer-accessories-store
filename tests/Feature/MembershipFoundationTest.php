<?php

namespace Tests\Feature;

use App\Actions\ApplyDeliveredOrderMembershipSpending;
use App\Enums\MembershipLevel;
use App\Enums\OrderStatus;
use App\Models\MembershipHistory;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class MembershipFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_casts_relationships_and_constraints_match_the_membership_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('membership_histories', [
            'id', 'user_id', 'old_tier', 'new_tier', 'spending_vnd', 'reason', 'requested_by', 'created_at',
        ]));

        $customer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $history = $this->history($customer, $admin);

        $this->assertSame(MembershipLevel::Dong, $history->old_tier);
        $this->assertSame(MembershipLevel::Bac, $history->new_tier);
        $this->assertSame($customer->id, $history->customer->id);
        $this->assertSame($admin->id, $history->requester->id);
        $this->assertSame($history->id, $customer->membershipHistories()->firstOrFail()->id);

        $admin->delete();
        $this->assertNull($history->refresh()->requested_by);

        foreach ([
            ['old_tier' => 'unknown'],
            ['new_tier' => 'unknown'],
            ['old_tier' => 'bac', 'new_tier' => 'bac'],
            ['spending_vnd' => -1],
            ['reason' => '   '],
            ['reason' => str_repeat('a', 41)],
        ] as $invalid) {
            try {
                DB::table('membership_histories')->insert(array_merge([
                    'user_id' => $customer->id,
                    'old_tier' => 'dong',
                    'new_tier' => 'bac',
                    'spending_vnd' => 5_000_000,
                    'reason' => 'delivered_order',
                    'requested_by' => null,
                    'created_at' => now(),
                ], $invalid));
                $this->fail('Invalid membership history must be rejected by the database.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_delivered_order_recalculates_product_spending_and_is_idempotent(): void
    {
        $customer = User::factory()->create(['membership_spending' => 123]);
        $first = $this->deliveredOrder($customer, 6_000_000, 1_000_000, 500_000, 500_000);
        $action = app(ApplyDeliveredOrderMembershipSpending::class);

        $result = $action->handle($first);
        $this->assertSame(5_000_000, $result->membership_spending);
        $this->assertSame(MembershipLevel::Bac, $result->current_tier);
        $this->assertDatabaseHas('membership_histories', [
            'user_id' => $customer->id,
            'old_tier' => 'dong',
            'new_tier' => 'bac',
            'spending_vnd' => 5_000_000,
            'reason' => 'delivered_order',
        ]);

        $action->handle($first);
        $this->assertSame(1, MembershipHistory::query()->count());
        $this->assertSame(5_000_000, $customer->fresh()->membership_spending);

        $second = $this->deliveredOrder($customer, 10_000_000, 0, 9_000_000, 0);
        $action->handle($second);
        $this->assertSame(15_000_000, $customer->fresh()->membership_spending);
        $this->assertSame(MembershipLevel::Vang, $customer->fresh()->current_tier);
        $this->assertSame(2, MembershipHistory::query()->count());

        $action->handle($first);
        $this->assertSame(15_000_000, $customer->fresh()->membership_spending);
        $this->assertSame(2, MembershipHistory::query()->count());
    }

    public function test_spending_updates_without_history_when_tier_does_not_change(): void
    {
        $customer = User::factory()->create();
        $order = $this->deliveredOrder($customer, 1_200_000, 200_000);

        app(ApplyDeliveredOrderMembershipSpending::class)->handle($order);

        $this->assertSame(1_000_000, $customer->fresh()->membership_spending);
        $this->assertSame(MembershipLevel::Dong, $customer->fresh()->current_tier);
        $this->assertDatabaseCount('membership_histories', 0);
    }

    public function test_action_rejects_non_delivered_or_non_customer_orders_without_writes(): void
    {
        $customer = User::factory()->create(['membership_spending' => 777]);
        $placed = Order::factory()->for($customer, 'customer')->create();

        try {
            app(ApplyDeliveredOrderMembershipSpending::class)->handle($placed);
            $this->fail('A non-delivered Order must not affect Membership.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }

        $admin = User::factory()->admin()->create();
        $adminOrder = $this->deliveredOrder($admin, 6_000_000);
        try {
            app(ApplyDeliveredOrderMembershipSpending::class)->handle($adminOrder);
            $this->fail('A non-Customer Order owner must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }

        $this->assertSame(777, $customer->fresh()->membership_spending);
        $this->assertSame(0, $admin->fresh()->membership_spending);
        $this->assertDatabaseCount('membership_histories', 0);
    }

    public function test_projection_and_history_are_atomic(): void
    {
        $customer = User::factory()->create();
        $order = $this->deliveredOrder($customer, 5_000_000);
        Event::listen('eloquent.creating: '.MembershipHistory::class, fn () => throw new RuntimeException('forced history failure'));

        try {
            app(ApplyDeliveredOrderMembershipSpending::class)->handle($order);
            $this->fail('A history failure must roll back the projection.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced history failure', $exception->getMessage());
        }

        $this->assertSame(0, $customer->fresh()->membership_spending);
        $this->assertSame(MembershipLevel::Dong, $customer->fresh()->current_tier);
        $this->assertDatabaseCount('membership_histories', 0);
    }

    public function test_membership_history_is_append_only_in_model_and_database(): void
    {
        $history = $this->history(User::factory()->create());

        try {
            $history->forceFill(['reason' => 'changed'])->save();
            $this->fail('Membership History updates must be blocked by the model.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        try {
            $history->delete();
            $this->fail('Membership History deletes must be blocked by the model.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        try {
            DB::table('membership_histories')->where('id', $history->id)->update(['reason' => 'changed']);
            $this->fail('Membership History updates must be blocked by the database.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        try {
            DB::table('membership_histories')->where('id', $history->id)->delete();
            $this->fail('Membership History deletes must be blocked by the database.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseCount('membership_histories', 1);
    }

    public function test_migration_refuses_partial_state_and_round_trips_only_when_history_is_empty(): void
    {
        $customer = User::factory()->create();
        $before = (array) DB::table('users')->where('id', $customer->id)->firstOrFail();
        $migration = require database_path('migrations/2026_10_01_000000_create_membership_histories_table.php');

        try {
            $migration->up();
            $this->fail('An existing Membership History table must be treated as a partial state.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('partial migration state', $exception->getMessage());
        }

        $migration->down();
        $this->assertFalse(Schema::hasTable('membership_histories'));
        $migration->up();

        $this->assertTrue(Schema::hasTable('membership_histories'));
        $after = (array) DB::table('users')->where('id', $customer->id)->firstOrFail();
        $this->assertSame($before, $after);
    }

    public function test_migration_down_refuses_to_delete_membership_history(): void
    {
        $history = $this->history(User::factory()->create());
        $migration = require database_path('migrations/2026_10_01_000000_create_membership_histories_table.php');

        try {
            $migration->down();
            $this->fail('Migration down must not delete historical Membership rows.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No history was deleted', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('membership_histories'));
        $this->assertDatabaseHas('membership_histories', ['id' => $history->id]);
    }

    public function test_overflow_rolls_back_without_clamping_membership_spending(): void
    {
        $customer = User::factory()->create();
        $first = $this->deliveredOrder($customer, PHP_INT_MAX);
        $this->deliveredOrder($customer, 1);

        $this->expectException(RuntimeException::class);
        try {
            app(ApplyDeliveredOrderMembershipSpending::class)->handle($first);
        } finally {
            $this->assertSame(0, $customer->fresh()->membership_spending);
            $this->assertDatabaseCount('membership_histories', 0);
        }
    }

    private function history(User $customer, ?User $requester = null): MembershipHistory
    {
        $history = new MembershipHistory;
        $history->forceFill([
            'user_id' => $customer->id,
            'old_tier' => MembershipLevel::Dong,
            'new_tier' => MembershipLevel::Bac,
            'spending_vnd' => 5_000_000,
            'reason' => 'recalculation',
            'requested_by' => $requester?->id,
            'created_at' => now(),
        ])->save();

        return $history;
    }

    private function deliveredOrder(
        User $customer,
        int $subtotal,
        int $discount = 0,
        int $shippingFee = 0,
        int $shippingDiscount = 0,
    ): Order {
        return Order::factory()->for($customer, 'customer')->create([
            'status' => OrderStatus::Delivered,
            'items_subtotal_vnd' => $subtotal,
            'item_discount_vnd' => $discount,
            'shipping_fee_vnd' => $shippingFee,
            'shipping_discount_vnd' => $shippingDiscount,
            'total_vnd' => $subtotal - $discount + $shippingFee - $shippingDiscount,
            'delivered_at' => now(),
        ]);
    }
}
