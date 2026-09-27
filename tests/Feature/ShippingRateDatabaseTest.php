<?php

namespace Tests\Feature;

use App\Enums\ShippingRegion;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShippingRateDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_constraints_indexes_and_initial_configuration_are_correct(): void
    {
        $this->assertTrue(Schema::hasColumns('shipping_rates', [
            'id', 'region_key', 'fee_vnd', 'updated_by', 'created_at', 'updated_at',
        ]));

        $rates = ShippingRate::query()->orderBy('region_key')->get();
        $this->assertCount(2, $rates);
        $this->assertSame(['ha_noi', 'other'], $rates->pluck('region_key')->map->value->all());
        $this->assertSame([30_000, 45_000], $rates->pluck('fee_vnd')->all());

        $indexes = collect(DB::select("PRAGMA index_list('shipping_rates')"))->pluck('name');
        $this->assertTrue($indexes->contains(fn ($name) => str_contains($name, 'region_key_unique')));
        $this->assertTrue($indexes->contains(fn ($name) => str_contains($name, 'updated_by_index')));
    }

    public function test_database_rejects_unknown_region_negative_fee_and_duplicate_region(): void
    {
        $this->assertConstraintViolation(fn () => DB::table('shipping_rates')->insert([
            'region_key' => 'unknown',
            'fee_vnd' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertConstraintViolation(fn () => DB::table('shipping_rates')
            ->where('region_key', 'ha_noi')->update(['fee_vnd' => -1]));

        $this->assertConstraintViolation(fn () => DB::table('shipping_rates')->insert([
            'region_key' => 'ha_noi',
            'fee_vnd' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertDatabaseCount('shipping_rates', 2);
        $this->assertDatabaseHas('shipping_rates', ['region_key' => 'ha_noi', 'fee_vnd' => 30_000]);
    }

    public function test_zero_and_signed_bigint_maximum_are_valid_boundaries(): void
    {
        $haNoi = ShippingRate::query()->where('region_key', ShippingRegion::HaNoi)->firstOrFail();
        $other = ShippingRate::query()->where('region_key', ShippingRegion::Other)->firstOrFail();

        $haNoi->forceFill(['fee_vnd' => 0])->save();
        $other->forceFill(['fee_vnd' => PHP_INT_MAX])->save();

        $this->assertSame(0, $haNoi->fresh()->fee_vnd);
        $this->assertSame(PHP_INT_MAX, $other->fresh()->fee_vnd);
    }

    public function test_user_foreign_key_sets_null_on_delete(): void
    {
        $admin = User::factory()->admin()->create();
        $rate = ShippingRate::query()->where('region_key', ShippingRegion::HaNoi)->firstOrFail();
        $rate->forceFill(['updated_by' => $admin->id])->save();

        $admin->delete();

        $this->assertNull($rate->fresh()->updated_by);
    }

    public function test_region_keys_cannot_be_changed_or_deleted_directly(): void
    {
        $rate = ShippingRate::query()->where('region_key', ShippingRegion::HaNoi)->firstOrFail();

        $this->assertConstraintViolation(fn () => DB::table('shipping_rates')
            ->where('id', $rate->id)->update(['region_key' => 'other']));
        $this->assertConstraintViolation(fn () => DB::table('shipping_rates')
            ->where('id', $rate->id)->delete());

        $this->assertDatabaseCount('shipping_rates', 2);
        $this->assertDatabaseHas('shipping_rates', ['id' => $rate->id, 'region_key' => 'ha_noi']);
    }

    public function test_enum_integer_cast_route_key_relationship_and_factory_are_valid(): void
    {
        $admin = User::factory()->admin()->create();
        $rate = ShippingRate::query()->where('region_key', 'other')->firstOrFail();
        $rate->forceFill(['updated_by' => $admin->id])->save();

        $this->assertSame(ShippingRegion::Other, $rate->fresh()->region_key);
        $this->assertIsInt($rate->fresh()->fee_vnd);
        $this->assertTrue($rate->fresh()->updater->is($admin));
        $this->assertSame('region_key', $rate->getRouteKeyName());

        $factoryRate = ShippingRate::factory()->other()->make();
        $this->assertSame(ShippingRegion::Other, $factoryRate->region_key);
        $this->assertSame(45_000, $factoryRate->fee_vnd);
    }

    public function test_sqlite_schema_contains_named_checks_foreign_key_and_region_guards(): void
    {
        $sql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'shipping_rates')->value('sql');
        $triggers = DB::table('sqlite_master')->where('type', 'trigger')
            ->whereIn('name', ['shipping_rates_region_key_guard', 'shipping_rates_delete_guard'])
            ->pluck('name')->sort()->values()->all();

        $this->assertStringContainsString('shipping_rates_region_key_check', $sql);
        $this->assertStringContainsString('shipping_rates_fee_vnd_check', $sql);
        $this->assertStringContainsString("region_key IN ('ha_noi', 'other')", $sql);
        $this->assertStringContainsString('fee_vnd >= 0', $sql);
        $this->assertStringContainsString('ON DELETE SET NULL', $sql);
        $this->assertSame(['shipping_rates_delete_guard', 'shipping_rates_region_key_guard'], $triggers);
    }

    public function test_migration_refuses_partial_existing_table_without_deleting_rows(): void
    {
        $rate = ShippingRate::query()->firstOrFail();
        $migration = require database_path('migrations/2026_09_27_000004_create_shipping_rates_table.php');

        try {
            $migration->up();
            $this->fail('Expected existing shipping_rates table to stop migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no automatic cleanup', $exception->getMessage());
            $this->assertDatabaseHas('shipping_rates', ['id' => $rate->id]);
        }
    }

    public function test_isolated_down_up_preserves_existing_application_data_and_restores_defaults(): void
    {
        $user = User::factory()->create();
        $migration = require database_path('migrations/2026_09_27_000004_create_shipping_rates_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('shipping_rates'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $migration->up();
        $this->assertTrue(Schema::hasTable('shipping_rates'));
        $this->assertDatabaseCount('shipping_rates', 2);
        $this->assertDatabaseHas('shipping_rates', ['region_key' => 'ha_noi', 'fee_vnd' => 30_000]);
        $this->assertDatabaseHas('shipping_rates', ['region_key' => 'other', 'fee_vnd' => 45_000]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    private function assertConstraintViolation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected database constraint violation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
