<?php

namespace Tests\Feature;

use App\Actions\GetAdminDashboardStats;
use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryAdjustmentRequest;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_active_admin_can_open_the_dashboard(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs(User::factory()->employee()->create())->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))
            ->assertOk()->assertSee('Tổng quan quản trị');
    }

    public function test_locked_and_inactive_admin_sessions_are_revoked(): void
    {
        foreach ([User::factory()->admin()->locked()->create(), User::factory()->admin()->inactive()->create()] as $admin) {
            $this->actingAs($admin)->get(route('admin.dashboard'))->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_invalid_stored_role_or_status_fails_safely(): void
    {
        $invalidRole = User::factory()->admin()->create();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            DB::table('users')->where('id', $invalidRole->id)->update(['role' => 'unknown']);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $this->actingAs($invalidRole)->get(route('admin.dashboard'))->assertForbidden();

        $invalidStatus = User::factory()->admin()->create();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            DB::table('users')->where('id', $invalidStatus->id)->update(['status' => 'unknown']);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $this->actingAs($invalidStatus)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_empty_tables_have_zero_counts_and_safe_role_shares(): void
    {
        $stats = app(GetAdminDashboardStats::class)->handle();

        $this->assertSame(0, $stats['users']['total']);
        $this->assertSame(['customer' => 0, 'employee' => 0, 'admin' => 0], $stats['users']['roles']);
        $this->assertSame(['active' => 0, 'locked' => 0, 'inactive' => 0], $stats['users']['statuses']);
        $this->assertSame(['customer' => 0, 'employee' => 0, 'admin' => 0], $stats['users']['role_shares']);
        $this->assertSame(['total' => 0, 'roots' => 0, 'children' => 0, 'visible' => 0, 'hidden' => 0], $stats['categories']);
        $this->assertSame(['products' => 0, 'low_stock' => 0, 'out_of_stock' => 0, 'pending_adjustments' => 0], $stats['inventory']);

        $values = [
            $stats['users']['total'],
            ...array_values($stats['users']['roles']),
            ...array_values($stats['users']['statuses']),
            ...array_values($stats['users']['role_shares']),
            ...array_values($stats['categories']),
            ...array_values($stats['inventory']),
        ];

        foreach ($values as $value) {
            $this->assertIsInt($value);
            $this->assertGreaterThanOrEqual(0, $value);
        }
    }

    public function test_aggregates_match_users_and_categories_without_loading_rows(): void
    {
        User::factory()->admin()->create();
        User::factory()->admin()->create();
        User::factory()->count(2)->create();
        User::factory()->locked()->create();
        User::factory()->employee()->create();
        User::factory()->employee()->inactive()->create();

        $visibleRoot = Category::factory()->create();
        Category::factory()->hidden()->create();
        Category::factory()->create(['parent_id' => $visibleRoot->id]);
        Category::factory()->hidden()->create(['parent_id' => $visibleRoot->id]);
        $brand = Brand::factory()->create();
        $outOfStock = Product::factory()->for($visibleRoot)->for($brand)->create(['sellable_quantity' => 0]);
        Product::factory()->for($visibleRoot)->for($brand)->create(['sellable_quantity' => 3, 'low_stock_threshold' => 5]);
        Product::factory()->for($visibleRoot)->for($brand)->create(['sellable_quantity' => 5, 'low_stock_threshold' => 5]);
        Product::factory()->for($visibleRoot)->for($brand)->create(['sellable_quantity' => 8, 'low_stock_threshold' => 5]);
        InventoryAdjustmentRequest::factory()->create([
            'product_id' => $outOfStock->id,
            'requested_by' => User::query()->firstOrFail()->id,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $stats = app(GetAdminDashboardStats::class)->handle();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(3, $queries);
        $this->assertStringContainsString('group by', strtolower($queries[0]['query']));
        $this->assertStringNotContainsString('select *', strtolower($queries[0]['query']));
        $this->assertStringNotContainsString('email', strtolower($queries[0]['query']));
        $this->assertSame(7, $stats['users']['total']);
        $this->assertSame(['customer' => 3, 'employee' => 2, 'admin' => 2], $stats['users']['roles']);
        $this->assertSame(['active' => 5, 'locked' => 1, 'inactive' => 1], $stats['users']['statuses']);
        $this->assertSame(['customer' => 42, 'employee' => 28, 'admin' => 28], $stats['users']['role_shares']);
        $this->assertSame(98, array_sum($stats['users']['role_shares']));
        $this->assertLessThan(count($stats['users']['role_shares']), 100 - array_sum($stats['users']['role_shares']));
        $this->assertSame($stats['users']['total'], array_sum($stats['users']['roles']));
        $this->assertSame($stats['users']['total'], array_sum($stats['users']['statuses']));
        $this->assertSame(['total' => 4, 'roots' => 2, 'children' => 2, 'visible' => 2, 'hidden' => 2], $stats['categories']);
        $this->assertSame($stats['categories']['total'], $stats['categories']['roots'] + $stats['categories']['children']);
        $this->assertSame($stats['categories']['total'], $stats['categories']['visible'] + $stats['categories']['hidden']);
        $this->assertSame(['products' => 4, 'low_stock' => 2, 'out_of_stock' => 1, 'pending_adjustments' => 1], $stats['inventory']);
        foreach ($stats['users']['role_shares'] as $share) {
            $this->assertGreaterThanOrEqual(0, $share);
            $this->assertLessThanOrEqual(100, $share);
        }
    }

    public function test_dashboard_renders_real_values_semantic_chart_and_only_live_links(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(2)->create();
        Category::factory()->create();
        Category::factory()->hidden()->create();

        $response = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $response->assertViewHas('stats', fn (array $stats) => $stats['users']['total'] === 3
            && $stats['users']['roles']['customer'] === 2
            && $stats['categories']['total'] === 2
            && $stats['categories']['visible'] === 1
        );
        $response->assertSee('id="metric-users-total">3</dd>', false)
            ->assertSee('<progress', false)
            ->assertSee('aria-label="Khách hàng: 2 tài khoản, 66%"', false)
            ->assertSee('href="'.route('admin.categories.index').'"', false)
            ->assertSee('href="'.route('admin.categories.create').'"', false)
            ->assertSee('href="'.route('admin.products.index').'"', false)
            ->assertSee('href="'.route('admin.brands.index').'"', false)
            ->assertSee('href="'.route('home').'"', false)
            ->assertSee('Tiếp tục công việc')
            ->assertSee('Xử lý đơn hàng')
            ->assertSee('Hỗ trợ khách hàng')
            ->assertSee('Hoàn tiền VNPay')
            ->assertDontSee('Đã triển khai')
            ->assertDontSee('Chưa triển khai')
            ->assertDontSee('Đánh giá sản phẩm')
            ->assertSee('href="'.route('admin.orders.index').'"', false)
            ->assertDontSee('href="/cart"', false)
            ->assertDontSee('Doanh thu')
            ->assertDontSee('Tổng đơn hàng');
    }

    public function test_admin_name_is_escaped_and_private_fields_are_absent(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => '<script>alert(1)</script>',
            'email' => 'dashboard-private@example.test',
            'phone' => '0912345678',
            'address' => 'Private dashboard address',
        ]);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('dashboard-private@example.test')
            ->assertDontSee('0912345678')
            ->assertDontSee('Private dashboard address');
    }

    public function test_customer_and_employee_dashboards_do_not_receive_admin_statistics(): void
    {
        foreach ([User::factory()->create(), User::factory()->employee()->create()] as $user) {
            $this->actingAs($user)
                ->get(route($user->role->dashboardRouteName()))
                ->assertOk()
                ->assertViewIs('dashboard')
                ->assertViewMissing('stats')
                ->assertDontSee('Tổng quan quản trị');
        }
    }

    public function test_employee_dashboard_prioritizes_only_authorized_operational_tasks(): void
    {
        $employee = User::factory()->employee()->create(['name' => 'Nhân viên ca sáng']);

        $this->actingAs($employee)
            ->get(route('employee.dashboard'))
            ->assertOk()
            ->assertSee('Xin chào, Nhân viên ca sáng')
            ->assertSee('href="'.route('employee.orders.index').'"', false)
            ->assertSee('href="'.route('inventory.index').'"', false)
            ->assertSee('href="'.route('employee.order-cancellation-requests.index').'"', false)
            ->assertSee('href="'.route('employee.support.index').'"', false)
            ->assertDontSee('Dashboard nền tảng')
            ->assertDontSee('Chưa có dữ liệu nghiệp vụ')
            ->assertDontSee('href="'.route('admin.refunds.index').'"', false)
            ->assertDontSee('href="'.route('admin.products.index').'"', false);
    }

    public function test_dashboard_keeps_get_only_route_and_role_redirect(): void
    {
        $route = Route::getRoutes()->getByName('admin.dashboard');
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(['web', 'auth', 'active', 'role:admin'], $route->gatherMiddleware());

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));
        $this->post(route('admin.dashboard'))->assertMethodNotAllowed();
        $this->get(route('admin.dashboard', ['role' => 'customer']))->assertOk()->assertSee('Tổng quan quản trị');
    }
}
