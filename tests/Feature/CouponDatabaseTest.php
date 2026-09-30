<?php

namespace Tests\Feature;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Enums\MembershipLevel;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CouponDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_coupon_tables_columns_indexes_defaults_and_enum_casts_exist(): void
    {
        foreach (['coupons', 'coupon_products', 'coupon_categories', 'coupon_brands'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        foreach (['code', 'type', 'scope', 'value', 'min_subtotal_vnd', 'required_tier', 'max_uses', 'max_uses_per_user', 'starts_at', 'ends_at', 'is_active'] as $column) {
            $this->assertTrue(Schema::hasColumn('coupons', $column));
        }

        $coupon = Coupon::factory()->create(['required_tier' => MembershipLevel::Vang]);
        $this->assertSame(CouponType::Percent, $coupon->type);
        $this->assertSame(CouponScope::Cart, $coupon->scope);
        $this->assertSame(MembershipLevel::Vang, $coupon->required_tier);
        $this->assertTrue($coupon->is_active);
        $this->assertIsInt($coupon->value);
    }

    public function test_database_accepts_every_valid_type_and_scope_value(): void
    {
        foreach ([
            [CouponType::Percent, 1], [CouponType::Percent, 100],
            [CouponType::Fixed, 1], [CouponType::FreeShipping, 0],
        ] as [$type, $value]) {
            Coupon::factory()->create(['type' => $type, 'value' => $value]);
        }
        foreach (CouponScope::cases() as $scope) {
            Coupon::factory()->create(['scope' => $scope]);
        }

        $this->assertDatabaseCount('coupons', 8);
    }

    public function test_database_rejects_invalid_type_scope_value_tier_limits_window_and_boolean(): void
    {
        $cases = [
            ['type' => 'bogus'], ['scope' => 'all'], ['type' => 'percent', 'value' => 0],
            ['type' => 'percent', 'value' => 101], ['type' => 'fixed', 'value' => 0],
            ['type' => 'free_shipping', 'value' => 1], ['required_tier' => 'platinum'],
            ['max_uses' => 0], ['max_uses_per_user' => 0],
            ['starts_at' => '2026-01-02 00:00:00', 'ends_at' => '2026-01-01 00:00:00'],
            ['is_active' => 2],
        ];

        foreach ($cases as $index => $override) {
            try {
                DB::table('coupons')->insert(array_merge($this->row('INVALID'.$index), $override));
                $this->fail('Expected database constraint failure for case '.$index);
            } catch (QueryException) {
                $this->assertDatabaseMissing('coupons', ['code' => 'INVALID'.$index]);
            }
        }
    }

    public function test_code_is_unique_case_insensitively_and_target_composite_keys_are_unique(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'SAVE10', 'scope' => CouponScope::Product]);
        $product = Product::factory()->create();
        DB::table('coupon_products')->insert(['coupon_id' => $coupon->id, 'product_id' => $product->id]);

        foreach ([
            fn () => DB::table('coupons')->insert($this->row('save10')),
            fn () => DB::table('coupon_products')->insert(['coupon_id' => $coupon->id, 'product_id' => $product->id]),
        ] as $insert) {
            try {
                $insert();
                $this->fail('Expected unique constraint failure.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_target_scope_triggers_reject_wrong_tables_and_scope_change_with_targets(): void
    {
        $product = Product::factory()->create();
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $coupon = Coupon::factory()->create(['scope' => CouponScope::Product]);
        DB::table('coupon_products')->insert(['coupon_id' => $coupon->id, 'product_id' => $product->id]);

        foreach ([
            fn () => DB::table('coupon_categories')->insert(['coupon_id' => $coupon->id, 'category_id' => $category->id]),
            fn () => DB::table('coupon_brands')->insert(['coupon_id' => $coupon->id, 'brand_id' => $brand->id]),
            fn () => DB::table('coupons')->where('id', $coupon->id)->update(['scope' => 'brand']),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected target scope trigger failure.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseCount('coupon_products', 1);
        $this->assertSame('product', $coupon->fresh()->getRawOriginal('scope'));
    }

    public function test_target_foreign_keys_restrict_parent_and_target_deletion(): void
    {
        $coupon = Coupon::factory()->create(['scope' => CouponScope::Product]);
        $product = Product::factory()->create();
        DB::table('coupon_products')->insert(['coupon_id' => $coupon->id, 'product_id' => $product->id]);

        foreach ([fn () => $coupon->delete(), fn () => $product->delete()] as $delete) {
            try {
                $delete();
                $this->fail('Expected foreign key restriction.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_model_relations_return_targets_and_no_soft_delete_column_exists(): void
    {
        $coupon = Coupon::factory()->create(['scope' => CouponScope::Brand]);
        $brands = Brand::factory()->count(2)->create();
        foreach ($brands as $brand) {
            DB::table('coupon_brands')->insert(['coupon_id' => $coupon->id, 'brand_id' => $brand->id]);
        }

        $this->assertSame($brands->pluck('id')->sort()->values()->all(), collect($coupon->targetIds())->sort()->values()->all());
        $this->assertFalse(Schema::hasColumn('coupons', 'deleted_at'));
        $this->assertTrue(Schema::hasTable('coupon_usages'));
        $this->assertTrue($coupon->usages->isEmpty());
    }

    /** @return array<string, mixed> */
    private function row(string $code): array
    {
        return [
            'code' => $code,
            'type' => 'percent',
            'scope' => 'cart',
            'value' => 10,
            'min_subtotal_vnd' => 0,
            'required_tier' => null,
            'max_uses' => null,
            'max_uses_per_user' => null,
            'starts_at' => '2026-01-01 00:00:00',
            'ends_at' => '2026-12-31 00:00:00',
            'is_active' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
    }
}
