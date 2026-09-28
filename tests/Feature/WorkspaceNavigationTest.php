<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WorkspaceNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_use_the_workspace_shell_without_the_storefront_chrome(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $product = Product::factory()->for($category)->for($brand)->create();
        $coupon = Coupon::factory()->create();
        $rate = ShippingRate::query()->where('region_key', 'ha_noi')->firstOrFail();

        $urls = [
            route('admin.dashboard'),
            route('admin.categories.index'),
            route('admin.categories.create'),
            route('admin.categories.edit', $category),
            route('admin.brands.index'),
            route('admin.brands.create'),
            route('admin.brands.edit', $brand),
            route('admin.products.index'),
            route('admin.products.create'),
            route('admin.products.edit', $product),
            route('admin.coupons.index'),
            route('admin.coupons.create'),
            route('admin.coupons.edit', $coupon),
            route('inventory.index'),
            route('admin.shipping-rates.index'),
            route('admin.shipping-rates.edit', $rate),
        ];

        $this->actingAs($admin);

        foreach ($urls as $url) {
            $this->get($url)->assertOk()
                ->assertSee('data-workspace-shell', false)
                ->assertSee('aria-label="Điều hướng quản trị"', false)
                ->assertDontSee('class="site-header"', false)
                ->assertDontSee('class="site-footer"', false);
        }
    }

    public function test_employee_workspace_only_shows_authorized_navigation(): void
    {
        $employee = User::factory()->employee()->create(['name' => '<b>Nhân viên tên dài</b>']);
        $response = $this->actingAs($employee)->get(route('employee.dashboard'))->assertOk();

        $response->assertSee('data-workspace-shell', false)
            ->assertSee('&lt;b&gt;Nhân viên tên dài&lt;/b&gt;', false)
            ->assertSee('href="'.route('employee.dashboard').'"', false)
            ->assertSee('href="'.route('inventory.index').'"', false)
            ->assertSee('href="'.route('home').'"', false)
            ->assertDontSee('href="'.route('admin.categories.index').'"', false)
            ->assertDontSee('href="'.route('admin.brands.index').'"', false)
            ->assertDontSee('href="'.route('admin.products.index').'"', false)
            ->assertDontSee('href="'.route('admin.shipping-rates.index').'"', false)
            ->assertDontSee('href="'.route('admin.coupons.index').'"', false)
            ->assertDontSee('/admin/orders', false)
            ->assertDontSee('/admin/reviews', false);
    }

    public function test_storefront_and_customer_pages_never_render_the_workspace_sidebar(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertSee('class="site-header"', false)
            ->assertDontSee('data-workspace-shell', false);

        $customer = User::factory()->create();
        $this->actingAs($customer)->get(route('customer.dashboard'))->assertOk()
            ->assertSee('class="site-header"', false)
            ->assertDontSee('data-workspace-shell', false)
            ->assertDontSee('aria-label="Điều hướng quản trị"', false);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('home'))->assertOk()
            ->assertDontSee('data-workspace-shell', false)
            ->assertDontSee('href="'.route('inventory.index').'"', false)
            ->assertDontSee('href="'.route('admin.categories.index').'"', false)
            ->assertDontSee('href="'.route('admin.coupons.index').'"', false)
            ->assertDontSee('href="'.route('admin.shipping-rates.index').'"', false);
    }

    public function test_admin_navigation_has_only_live_links_post_logout_and_accessible_drawer_controls(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('href="'.route('admin.dashboard').'"', false)
            ->assertSee('href="'.route('admin.categories.index').'"', false)
            ->assertSee('href="'.route('admin.brands.index').'"', false)
            ->assertSee('href="'.route('admin.products.index').'"', false)
            ->assertSee('href="'.route('inventory.index').'"', false)
            ->assertSee('href="'.route('admin.shipping-rates.index').'"', false)
            ->assertSee('method="POST" action="'.route('logout').'"', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee('href="'.route('logout').'"', false)
            ->assertSee('aria-label="Mở menu quản trị"', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('aria-controls="workspace-sidebar"', false)
            ->assertSee('data-workspace-overlay', false)
            ->assertSee('href="#workspace-main"', false)
            ->assertSee('href="'.route('admin.coupons.index').'"', false)
            ->assertDontSee('/admin/orders', false)
            ->assertDontSee('/admin/customers', false)
            ->assertDontSee('/admin/reviews', false)
            ->assertDontSee('/admin/payments', false)
            ->assertDontSee('/admin/refunds', false);
    }

    public function test_active_state_covers_index_create_edit_and_operations_route_families(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $product = Product::factory()->for($category)->for($brand)->create();
        $coupon = Coupon::factory()->create();
        $rate = ShippingRate::query()->where('region_key', 'ha_noi')->firstOrFail();
        $this->actingAs($admin);

        $cases = [
            [route('admin.dashboard'), route('admin.dashboard')],
            [route('admin.categories.index'), route('admin.categories.index')],
            [route('admin.categories.create'), route('admin.categories.index')],
            [route('admin.categories.edit', $category), route('admin.categories.index')],
            [route('admin.brands.index'), route('admin.brands.index')],
            [route('admin.brands.edit', $brand), route('admin.brands.index')],
            [route('admin.products.index'), route('admin.products.index')],
            [route('admin.products.edit', $product), route('admin.products.index')],
            [route('admin.coupons.index'), route('admin.coupons.index')],
            [route('admin.coupons.create'), route('admin.coupons.index')],
            [route('admin.coupons.edit', $coupon), route('admin.coupons.index')],
            [route('inventory.index'), route('inventory.index')],
            [route('inventory.history', $product), route('inventory.index')],
            [route('admin.shipping-rates.index'), route('admin.shipping-rates.index')],
            [route('admin.shipping-rates.edit', $rate), route('admin.shipping-rates.index')],
        ];

        foreach ($cases as [$page, $activeLink]) {
            $response = $this->get($page)->assertOk();

            $this->assertMatchesRegularExpression(
                '/href="'.preg_quote($activeLink, '/').'"\s+aria-current="page"/',
                $response->getContent(),
            );
        }
    }

    public function test_workspace_javascript_is_namespaced_and_supports_required_drawer_behaviors(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('[data-workspace-shell]', $javascript);
        $this->assertStringContainsString('[data-nav-toggle]', $javascript);
        $this->assertStringContainsString("event.key === 'Escape'", $javascript);
        $this->assertStringContainsString("event.key === 'Tab'", $javascript);
        $this->assertStringContainsString('workspaceSidebar.inert', $javascript);
        $this->assertStringContainsString('workspace-drawer-open', $javascript);
        $this->assertStringContainsString('[data-workspace-overlay]', $javascript);
        $this->assertStringContainsString("addEventListener('pageshow'", $javascript);
        $this->assertStringContainsString("addEventListener('pagehide'", $javascript);
    }

    public function test_workspace_partial_has_no_database_queries_and_route_middleware_is_unchanged(): void
    {
        $sidebar = file_get_contents(resource_path('views/partials/workspace-sidebar.blade.php'));

        $this->assertStringNotContainsString('::query', $sidebar);
        $this->assertStringNotContainsString('DB::', $sidebar);
        $this->assertStringNotContainsString('@php', $sidebar);

        foreach ([
            'admin.dashboard' => ['web', 'auth', 'active', 'role:admin'],
            'admin.categories.index' => ['web', 'auth', 'active', 'role:admin'],
            'admin.brands.index' => ['web', 'auth', 'active', 'role:admin'],
            'admin.products.index' => ['web', 'auth', 'active', 'role:admin'],
            'admin.coupons.index' => ['web', 'auth', 'active', 'role:admin'],
            'inventory.index' => ['web', 'auth', 'active', 'role:employee,admin'],
            'admin.shipping-rates.index' => ['web', 'auth', 'active', 'role:admin'],
        ] as $name => $middleware) {
            $this->assertSame($middleware, Route::getRoutes()->getByName($name)->gatherMiddleware());
        }
    }
}
