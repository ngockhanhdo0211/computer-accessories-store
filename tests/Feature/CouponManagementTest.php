<?php

namespace Tests\Feature;

use App\Actions\DeleteCoupon;
use App\Actions\SaveCoupon;
use App\Enums\CouponScope;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CouponManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'SAVE10', 'type' => 'percent', 'scope' => 'cart', 'value' => '10',
            'min_subtotal_vnd' => '100000', 'required_tier' => '', 'max_uses' => '',
            'max_uses_per_user' => '', 'starts_at' => '2026-09-01T00:00',
            'ends_at' => '2026-12-31T23:59', 'is_active' => '1',
        ], $overrides);
    }

    public function test_guest_customer_and_employee_cannot_use_coupon_crud(): void
    {
        $coupon = Coupon::factory()->create();
        $endpoints = fn () => [
            fn () => $this->get(route('admin.coupons.index')),
            fn () => $this->get(route('admin.coupons.create')),
            fn () => $this->post(route('admin.coupons.store'), $this->payload()),
            fn () => $this->get(route('admin.coupons.edit', $coupon)),
            fn () => $this->put(route('admin.coupons.update', $coupon), $this->payload()),
            fn () => $this->delete(route('admin.coupons.destroy', $coupon)),
        ];

        foreach ($endpoints() as $call) {
            $call()->assertRedirect(route('login'));
        }
        foreach ([User::factory()->create(), User::factory()->employee()->create()] as $user) {
            $this->actingAs($user);
            foreach ($endpoints() as $call) {
                $call()->assertForbidden();
            }
        }
    }

    public function test_admin_creates_normalized_cart_coupon_and_audit_atomically(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.coupons.store'), $this->payload(['code' => '  save-10  ']))
            ->assertRedirect()->assertSessionHas('status', 'Đã tạo mã giảm giá.');

        $coupon = Coupon::query()->where('code', 'SAVE-10')->sole();
        $this->assertSame(CouponScope::Cart, $coupon->scope);
        $this->assertDatabaseCount('coupon_products', 0);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'coupon.created', 'subject_id' => $coupon->id]);
    }

    public function test_admin_creates_each_target_scope_with_multiple_targets(): void
    {
        $admin = $this->admin();
        $productIds = Product::factory()->count(2)->create()->pluck('id')->all();
        $categoryIds = Category::factory()->count(2)->create()->pluck('id')->all();
        $brandIds = Brand::factory()->count(2)->create()->pluck('id')->all();
        $this->actingAs($admin);

        foreach ([
            ['product', 'product_ids', $productIds, 'coupon_products'],
            ['category', 'category_ids', $categoryIds, 'coupon_categories'],
            ['brand', 'brand_ids', $brandIds, 'coupon_brands'],
        ] as $index => [$scope, $field, $ids, $table]) {
            $this->post(route('admin.coupons.store'), $this->payload(['code' => 'TARGET'.$index, 'scope' => $scope, $field => $ids]))->assertRedirect();
            $this->assertSame(2, DB::table($table)->where('coupon_id', Coupon::query()->where('code', 'TARGET'.$index)->value('id'))->count());
        }
    }

    public function test_validation_enforces_type_value_scope_targets_time_limits_and_tier(): void
    {
        $product = Product::factory()->create();
        $this->actingAs($this->admin());
        $cases = [
            [['type' => 'percent', 'value' => '0'], 'value'],
            [['type' => 'percent', 'value' => '101'], 'value'],
            [['type' => 'fixed', 'value' => '0'], 'value'],
            [['type' => 'free_shipping', 'value' => '1'], 'value'],
            [['scope' => 'all'], 'scope'],
            [['scope' => 'product'], 'target_ids'],
            [['scope' => 'cart', 'product_ids' => [$product->id]], 'target_ids'],
            [['ends_at' => '2026-08-01T00:00'], 'ends_at'],
            [['max_uses' => '0'], 'max_uses'],
            [['max_uses_per_user' => '-1'], 'max_uses_per_user'],
            [['required_tier' => 'platinum'], 'required_tier'],
        ];

        foreach ($cases as [$override, $field]) {
            $this->post(route('admin.coupons.store'), $this->payload($override))->assertSessionHasErrors($field);
        }
        $this->assertDatabaseCount('coupons', 0);
    }

    public function test_update_changes_scope_and_targets_in_one_transaction_with_audit(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create();
        $brand = Brand::factory()->create();
        $coupon = Coupon::factory()->create(['scope' => CouponScope::Product]);
        DB::table('coupon_products')->insert(['coupon_id' => $coupon->id, 'product_id' => $product->id]);

        $this->actingAs($admin)->put(route('admin.coupons.update', $coupon), $this->payload([
            'code' => $coupon->code, 'scope' => 'brand', 'brand_ids' => [$brand->id],
        ]))->assertRedirect(route('admin.coupons.edit', $coupon));

        $this->assertDatabaseMissing('coupon_products', ['coupon_id' => $coupon->id]);
        $this->assertDatabaseHas('coupon_brands', ['coupon_id' => $coupon->id, 'brand_id' => $brand->id]);
        $audit = DB::table('audit_logs')->where('action', 'coupon.updated')->sole();
        $this->assertStringContainsString('product', $audit->before_json);
        $this->assertStringContainsString('brand', $audit->after_json);
    }

    public function test_audit_failure_rolls_back_create_update_targets_and_delete(): void
    {
        $admin = $this->admin();
        $brand = Brand::factory()->create();
        $coupon = Coupon::factory()->create(['scope' => CouponScope::Cart, 'value' => 10]);
        DB::unprepared("CREATE TRIGGER coupon_audit_failure BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'forced audit failure'); END");

        foreach ([
            fn () => app(SaveCoupon::class)->handle($this->actionPayload(['code' => 'ROLLBACK']), $admin),
            fn () => app(SaveCoupon::class)->handle($this->actionPayload(['code' => $coupon->code, 'scope' => 'brand', 'target_ids' => [$brand->id]]), $admin, $coupon),
            fn () => app(DeleteCoupon::class)->handle($coupon, $admin),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected audit failure.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseMissing('coupons', ['code' => 'ROLLBACK']);
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'scope' => 'cart', 'value' => 10]);
        $this->assertDatabaseCount('coupon_brands', 0);
    }

    public function test_duplicate_code_race_becomes_validation_error_without_extra_audit(): void
    {
        $admin = $this->admin();
        Coupon::factory()->create(['code' => 'RACE']);

        try {
            app(SaveCoupon::class)->handle($this->actionPayload(['code' => 'RACE']), $admin);
            $this->fail('Expected duplicate validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        $this->assertDatabaseCount('coupons', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_delete_removes_only_unused_coupon_targets_and_writes_audit(): void
    {
        $admin = $this->admin();
        $brand = Brand::factory()->create();
        $coupon = Coupon::factory()->create(['scope' => CouponScope::Brand]);
        DB::table('coupon_brands')->insert(['coupon_id' => $coupon->id, 'brand_id' => $brand->id]);

        $this->actingAs($admin)->delete(route('admin.coupons.destroy', $coupon))->assertRedirect(route('admin.coupons.index'));
        $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
        $this->assertDatabaseMissing('coupon_brands', ['coupon_id' => $coupon->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'coupon.deleted', 'subject_id' => $coupon->id]);
    }

    public function test_index_forms_workspace_navigation_and_active_states_are_accessible(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'ACCESS']);
        $this->actingAs($this->admin());

        $this->get(route('admin.coupons.index'))->assertOk()->assertSee('data-workspace-shell', false)
            ->assertSee('href="'.route('admin.coupons.index').'"', false)
            ->assertSee('aria-current="page"', false)
            ->assertDontSee('coupon usage');
        $this->get(route('admin.coupons.create'))->assertOk()->assertSee('name="_token"', false)
            ->assertSee('label for="code"', false)->assertSee('aria-current="page"', false);
        $this->get(route('admin.coupons.edit', $coupon))->assertOk()->assertSee('name="_method" value="PUT"', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_sidebar_and_routes_are_admin_only_and_no_public_apply_route_exists(): void
    {
        $link = 'href="'.route('admin.coupons.index').'"';
        $this->get(route('home'))->assertDontSee($link, false);
        $this->actingAs(User::factory()->create())->get(route('customer.dashboard'))->assertDontSee($link, false);
        $this->actingAs(User::factory()->employee()->create())->get(route('employee.dashboard'))->assertDontSee($link, false);
        $this->actingAs($this->admin())->get(route('admin.coupons.index'))->assertSee($link, false);
        $this->post('/coupons/apply', ['code' => 'SAVE10'])->assertNotFound();
        $this->post('/cart/coupon', ['code' => 'SAVE10'])->assertNotFound();
    }

    public function test_action_revalidates_input_and_rejects_non_admin_actor(): void
    {
        foreach ([
            fn () => app(SaveCoupon::class)->handle($this->actionPayload(['type' => 'free_shipping', 'value' => 1]), $this->admin()),
            fn () => app(SaveCoupon::class)->handle($this->actionPayload(), User::factory()->employee()->create()),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected validation exception.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_index_uses_bounded_queries_and_never_queries_coupon_usages(): void
    {
        Coupon::factory()->count(30)->create();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($this->admin())->get(route('admin.coupons.index'))->assertOk();
        $queries = DB::getQueryLog();
        $this->assertLessThanOrEqual(5, count($queries));
        $this->assertStringNotContainsString('coupon_usages', strtolower(json_encode($queries)));
        $response->assertDontSee('Lượt đã dùng');
    }

    public function test_index_renders_semantic_responsive_coupon_ledger_with_real_values_and_states(): void
    {
        $admin = $this->admin();
        $now = now('UTC');
        $product = Product::factory()->create(['name' => '<Thiết bị & phụ kiện>']);

        $percent = Coupon::factory()->create([
            'code' => 'SAVE10',
            'type' => 'percent',
            'scope' => 'product',
            'value' => 10,
            'min_subtotal_vnd' => 0,
            'required_tier' => null,
            'max_uses' => null,
            'max_uses_per_user' => null,
            'starts_at' => $now->copy()->subDay(),
            'ends_at' => $now->copy()->addDay(),
            'is_active' => true,
        ]);
        DB::table('coupon_products')->insert(['coupon_id' => $percent->id, 'product_id' => $product->id]);

        Coupon::factory()->fixed(50_000)->create([
            'code' => 'FIXED50',
            'scope' => 'cart',
            'starts_at' => $now->copy()->addDay(),
            'ends_at' => $now->copy()->addDays(2),
            'is_active' => true,
        ]);
        Coupon::factory()->freeShipping()->create([
            'code' => 'FREESHIP',
            'scope' => 'cart',
            'starts_at' => $now->copy()->subDays(2),
            'ends_at' => $now->copy()->subDay(),
            'is_active' => true,
        ]);
        Coupon::factory()->create([
            'code' => 'DISABLED',
            'starts_at' => $now->copy()->subDay(),
            'ends_at' => $now->copy()->addDay(),
            'is_active' => false,
        ]);
        Coupon::factory()->create([
            'code' => 'SAFE<&',
            'starts_at' => $now->copy()->subDay(),
            'ends_at' => $now->copy()->addDay(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.coupons.index'))->assertOk();

        $response->assertSee('<div class="coupon-toolbar"', false)
            ->assertSee('<header class="page-heading">', false)
            ->assertSee('class="status-badge status-badge--success"', false)
            ->assertSee('class="status-badge status-badge--info"', false)
            ->assertSee('class="status-badge status-badge--warning"', false)
            ->assertSee('class="status-badge status-badge--neutral"', false)
            ->assertSee('class="action-group coupon-actions"', false)
            ->assertSee('<strong>5</strong> mã giảm giá', false)
            ->assertSee('<table class="coupon-table">', false)
            ->assertSee('<thead>', false)
            ->assertSee('<tbody>', false)
            ->assertSee('10%')
            ->assertSee('50.000 ₫')
            ->assertSee('Miễn phí vận chuyển')
            ->assertSee('Toàn giỏ hàng')
            ->assertSee('Sản phẩm')
            ->assertSee('1 sản phẩm đã liên kết')
            ->assertSee('Không giới hạn')
            ->assertSee('Mọi hạng')
            ->assertSee('Đang hoạt động')
            ->assertSee('Chưa bắt đầu')
            ->assertSee('Đã hết hạn')
            ->assertSee('Đã tắt')
            ->assertSee('SAFE&lt;&amp;', false)
            ->assertDontSee('SAFE<&', false)
            ->assertDontSee('Lượt đã dùng')
            ->assertSee('href="'.route('admin.coupons.edit', $percent).'"', false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertSee('class="link-button coupon-delete-action"', false)
            ->assertSee('name="_token"', false);
    }

    public function test_empty_coupon_index_shows_actionable_state_without_empty_table(): void
    {
        $this->actingAs($this->admin())->get(route('admin.coupons.index'))
            ->assertOk()
            ->assertSee('<section class="empty-state coupon-empty-state"', false)
            ->assertSee('Chưa có mã giảm giá')
            ->assertSee('href="'.route('admin.coupons.create').'"', false)
            ->assertDontSee('<table', false)
            ->assertDontSee('<thead', false);
    }

    public function test_coupon_form_exposes_value_guidance_and_scope_target_controls(): void
    {
        Product::factory()->create();
        Category::factory()->create();
        Brand::factory()->create();

        $response = $this->actingAs($this->admin())->get(route('admin.coupons.create'))->assertOk();

        $response->assertSee('data-coupon-form', false)
            ->assertSee('<div class="field">', false)
            ->assertDontSee('field-group', false)
            ->assertSee('data-coupon-type', false)
            ->assertSee('data-coupon-value', false)
            ->assertSee('data-coupon-value-suffix', false)
            ->assertSee('Chỉ nhập số nguyên từ 1 đến 100.')
            ->assertSee('data-coupon-scope', false)
            ->assertSee('data-coupon-target-group="product"', false)
            ->assertSee('data-coupon-target-group="category"', false)
            ->assertSee('data-coupon-target-group="brand"', false);
    }

    public function test_coupon_integer_error_is_vietnamese_and_valid_old_target_input_is_preserved(): void
    {
        $product = Product::factory()->create();
        $this->actingAs($this->admin());

        $this->from(route('admin.coupons.create'))->post(route('admin.coupons.store'), $this->payload([
            'scope' => 'product',
            'product_ids' => [$product->id],
            'value' => '10.5',
        ]))->assertRedirect(route('admin.coupons.create'))
            ->assertSessionHasErrors(['value' => 'Giá trị ưu đãi phải là số nguyên.']);

        $this->get(route('admin.coupons.create'))
            ->assertOk()
            ->assertSee('value="'.$product->id.'" checked', false)
            ->assertSee('Giá trị ưu đãi phải là số nguyên.');
    }

    private function actionPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'ACTION', 'type' => 'percent', 'scope' => 'cart', 'value' => 10,
            'min_subtotal_vnd' => 0, 'required_tier' => null, 'max_uses' => null,
            'max_uses_per_user' => null, 'starts_at' => '2026-09-01T00:00',
            'ends_at' => '2026-12-31T23:59', 'is_active' => true,
        ], $overrides);
    }
}
