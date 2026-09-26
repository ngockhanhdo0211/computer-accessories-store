<?php

namespace Tests\Feature;

use App\Actions\SaveProduct;
use App\Enums\ProductVisibility;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_non_admin_roles_cannot_use_product_management_routes(): void
    {
        $product = Product::factory()->create();
        $routes = [
            ['get', route('admin.products.index'), []],
            ['get', route('admin.products.create'), []],
            ['post', route('admin.products.store'), $this->payload()],
            ['get', route('admin.products.edit', $product), []],
            ['put', route('admin.products.update', $product), $this->payload()],
            ['delete', route('admin.products.destroy', $product), []],
        ];

        foreach ($routes as [$method, $url, $data]) {
            $this->{$method}($url, $data)->assertRedirect(route('login'));
        }

        foreach ([User::factory()->create(), User::factory()->employee()->create()] as $user) {
            foreach ($routes as [$method, $url, $data]) {
                $this->actingAs($user)->{$method}($url, $data)->assertForbidden();
            }
        }
    }

    public function test_admin_sees_empty_index_and_accessible_create_form(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('admin.products.index'))->assertOk()
            ->assertSee('Chưa có sản phẩm')
            ->assertSee(route('admin.products.create'), false);
        $this->get(route('admin.products.create'))->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('name="category_id"', false)
            ->assertSee('name="brand_id"', false)
            ->assertSee('name="images[]"', false)
            ->assertDontSee('name="sellable_quantity"', false)
            ->assertDontSee('name="sold_quantity"', false)
            ->assertDontSee('name="damaged_quantity"', false);
    }

    public function test_admin_creates_normalized_product_with_server_projection_defaults_and_no_injection(): void
    {
        $this->actingAs($this->admin());
        $response = $this->post(route('admin.products.store'), $this->payload([
            'name' => '  Chuột   không dây  ',
            'slug' => '',
            'sku' => '  mouse-01  ',
            'short_description' => '  Kết nối   ổn định  ',
            'description' => '  Mô tả chi tiết.  ',
            'sellable_quantity' => 999,
            'damaged_quantity' => 999,
            'sold_quantity' => 999,
            'low_stock_threshold' => 999,
        ]));

        $product = Product::query()->sole();
        $response->assertRedirect(route('admin.products.edit', $product))->assertSessionHas('status');
        $this->assertSame('Chuột không dây', $product->name);
        $this->assertSame('chuot-khong-day', $product->slug);
        $this->assertSame('MOUSE-01', $product->sku);
        $this->assertSame('Kết nối ổn định', $product->short_description);
        $this->assertSame('Mô tả chi tiết.', $product->description);
        $this->assertSame(0, $product->sellable_quantity);
        $this->assertSame(0, $product->damaged_quantity);
        $this->assertSame(0, $product->sold_quantity);
        $this->assertSame(5, $product->low_stock_threshold);
    }

    public function test_admin_updates_product_name_without_implicit_slug_change_and_can_change_slug_explicitly(): void
    {
        $product = Product::factory()->create(['name' => 'Original', 'slug' => 'original', 'sku' => 'ORIGINAL-1']);
        $this->actingAs($this->admin());

        $this->put(route('admin.products.update', $product), $this->payload([
            'category_id' => $product->category_id,
            'brand_id' => $product->brand_id,
            'name' => 'Renamed product',
            'slug' => '',
            'sku' => $product->sku,
        ]))->assertRedirect(route('admin.products.edit', $product));
        $this->assertSame('original', $product->fresh()->slug);

        $this->patch(route('admin.products.update', $product), $this->payload([
            'category_id' => $product->category_id,
            'brand_id' => $product->brand_id,
            'name' => 'Renamed product',
            'slug' => 'New Product URL',
            'sku' => $product->sku,
        ]))->assertRedirect(route('admin.products.edit', 'new-product-url'));

        $this->get('/admin/products/original/edit')->assertNotFound();
        $this->get('/admin/products/new-product-url/edit')->assertOk();
    }

    public function test_admin_deletes_unreferenced_product_and_no_get_delete_route_exists(): void
    {
        $product = Product::factory()->create();
        $this->actingAs($this->admin());

        $this->get('/admin/products/'.$product->slug.'/delete')->assertNotFound();
        $this->post(route('admin.products.destroy', $product))->assertMethodNotAllowed();
        $this->delete(route('admin.products.destroy', $product))->assertRedirect(route('admin.products.index'))->assertSessionHas('status');
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_index_searches_filters_and_sorts_with_stable_results(): void
    {
        $categoryA = Category::factory()->create(['name' => 'Category A']);
        $categoryB = Category::factory()->create(['name' => 'Category B']);
        $brandA = Brand::factory()->create(['name' => 'Brand A']);
        $brandB = Brand::factory()->create(['name' => 'Brand B']);
        $alpha = Product::factory()->for($categoryA)->for($brandA)->create([
            'name' => 'Alpha Mouse', 'sku' => 'MOUSE-ALPHA', 'slug' => 'alpha-mouse', 'price_vnd' => 300000,
        ]);
        $beta = Product::factory()->for($categoryB)->for($brandB)->hidden()->create([
            'name' => 'Beta Hub', 'sku' => 'HUB-BETA', 'slug' => 'beta-hub', 'price_vnd' => 100000,
        ]);
        $gamma = Product::factory()->for($categoryA)->for($brandB)->create([
            'name' => 'Gamma Stand', 'sku' => 'STAND-GAMMA', 'slug' => 'gamma-stand', 'price_vnd' => 200000,
        ]);
        $this->actingAs($this->admin());

        $this->get(route('admin.products.index', ['search' => 'mouse']))->assertSee($alpha->name)->assertDontSee($beta->name);
        $this->get(route('admin.products.index', ['search' => 'hub-beta']))->assertSee($beta->name)->assertDontSee($alpha->name);
        $this->get(route('admin.products.index', ['category' => $categoryA->id]))->assertSee($alpha->name)->assertSee($gamma->name)->assertDontSee($beta->name);
        $this->get(route('admin.products.index', ['brand' => $brandA->id]))->assertSee($alpha->name)->assertDontSee($beta->name);
        $this->get(route('admin.products.index', ['visibility' => 'hidden']))->assertSee($beta->name)->assertDontSee($alpha->name);

        $asc = $this->get(route('admin.products.index', ['sort' => 'price_asc']))->viewData('products')->pluck('id')->all();
        $desc = $this->get(route('admin.products.index', ['sort' => 'price_desc']))->viewData('products')->pluck('id')->all();
        $this->assertSame([$beta->id, $gamma->id, $alpha->id], $asc);
        $this->assertSame([$alpha->id, $gamma->id, $beta->id], $desc);
    }

    public function test_index_paginates_redirects_out_of_range_and_distinguishes_no_results(): void
    {
        Product::factory()->count(25)->create(['name' => 'Same Product']);
        $this->actingAs($this->admin());

        $first = $this->get(route('admin.products.index', ['sort' => 'name']))->assertOk()->viewData('products');
        $this->assertCount(24, $first->items());
        $this->assertSame($first->pluck('id')->all(), $first->pluck('id')->sort()->values()->all());
        $this->get(route('admin.products.index', ['sort' => 'name', 'page' => 999]))
            ->assertRedirect(route('admin.products.index', ['sort' => 'name', 'page' => 2]));
        $this->get(route('admin.products.index', ['search' => 'not-found']))
            ->assertOk()->assertSee('Không tìm thấy sản phẩm')->assertDontSee('Chưa có sản phẩm');
    }

    public function test_validation_rejects_invalid_fields_and_preserves_safe_old_input(): void
    {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        Product::factory()->create(['sku' => 'TAKEN-SKU', 'slug' => 'taken-slug']);
        $this->actingAs($this->admin());

        $response = $this->from(route('admin.products.create'))->post(route('admin.products.store'), [
            'category_id' => 999999,
            'brand_id' => 999999,
            'name' => '   ',
            'slug' => 'taken-slug',
            'sku' => 'taken-sku',
            'short_description' => '   ',
            'description' => '   ',
            'price_vnd' => '10.5',
            'sale_price_vnd' => 20,
            'visibility' => 'draft',
            'images' => [],
        ]);

        $response->assertRedirect(route('admin.products.create'))->assertSessionHasErrors([
            'category_id', 'brand_id', 'name', 'slug', 'sku', 'short_description', 'description', 'price_vnd', 'visibility',
        ]);
        $this->assertDatabaseCount('products', 1);
        $this->assertSame($category->id, $category->fresh()->id);
        $this->assertSame($brand->id, $brand->fresh()->id);
    }

    public function test_money_boundaries_and_product_updates_preserve_inventory_projections(): void
    {
        $this->actingAs($this->admin());

        foreach ([
            ['overrides' => ['price_vnd' => 0], 'field' => 'price_vnd'],
            ['overrides' => ['price_vnd' => '10.5'], 'field' => 'price_vnd'],
            ['overrides' => ['price_vnd' => '1.000.000'], 'field' => 'price_vnd'],
            ['overrides' => ['price_vnd' => (string) PHP_INT_MAX.'0'], 'field' => 'price_vnd'],
            ['overrides' => ['sale_price_vnd' => 0], 'field' => 'sale_price_vnd'],
            ['overrides' => ['price_vnd' => 500000, 'sale_price_vnd' => 500000], 'field' => 'sale_price_vnd'],
            ['overrides' => ['price_vnd' => 500000, 'sale_price_vnd' => 500001], 'field' => 'sale_price_vnd'],
        ] as $case) {
            $this->post(route('admin.products.store'), $this->payload($case['overrides']))
                ->assertSessionHasErrors($case['field']);
        }

        $product = Product::factory()->create([
            'price_vnd' => 500000,
            'sale_price_vnd' => null,
            'low_stock_threshold' => 7,
            'sellable_quantity' => 6,
            'damaged_quantity' => 2,
            'sold_quantity' => 9,
        ]);
        $this->assertSame(500000, $product->effectivePriceVnd());

        $this->patch(route('admin.products.update', $product), $this->payload([
            'category_id' => $product->category_id,
            'brand_id' => $product->brand_id,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'price_vnd' => PHP_INT_MAX,
            'sale_price_vnd' => PHP_INT_MAX - 1,
            'low_stock_threshold' => 999,
            'sellable_quantity' => 999,
            'damaged_quantity' => 999,
            'sold_quantity' => 999,
        ]))->assertRedirect();

        $product->refresh();
        $this->assertSame(PHP_INT_MAX - 1, $product->effectivePriceVnd());
        $this->assertSame(7, $product->low_stock_threshold);
        $this->assertSame(6, $product->sellable_quantity);
        $this->assertSame(2, $product->damaged_quantity);
        $this->assertSame(9, $product->sold_quantity);
    }

    public function test_duplicate_sku_and_slug_races_become_precise_validation_errors_and_update_is_atomic(): void
    {
        $existing = Product::factory()->create(['sku' => 'TAKEN-SKU', 'slug' => 'taken-slug']);
        $target = Product::factory()->create(['name' => 'Original', 'sku' => 'ORIGINAL-SKU', 'slug' => 'original-slug']);

        foreach ([
            ['sku' => $existing->sku, 'slug' => 'free-slug', 'field' => 'sku'],
            ['sku' => 'FREE-SKU', 'slug' => $existing->slug, 'field' => 'slug'],
        ] as $case) {
            try {
                app(SaveProduct::class)->handle($this->actionPayload($target, $case), $target);
                $this->fail('Expected validation exception.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($case['field'], $exception->errors());
            }
            $target->refresh();
            $this->assertSame('Original', $target->name);
            $this->assertSame('ORIGINAL-SKU', $target->sku);
            $this->assertSame('original-slug', $target->slug);
        }
    }

    public function test_unrelated_unique_query_exception_is_not_disguised(): void
    {
        DB::statement('CREATE TABLE unrelated_product_unique (value TEXT UNIQUE)');
        DB::table('unrelated_product_unique')->insert(['value' => 'taken']);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER products_unrelated_unique
            BEFORE INSERT ON products
            BEGIN INSERT INTO unrelated_product_unique (value) VALUES ('taken'); END;
        SQL);

        $this->expectException(UniqueConstraintViolationException::class);
        app(SaveProduct::class)->handle($this->actionPayload());
    }

    public function test_action_rejects_invalid_direct_input_and_mass_assignment_excludes_projections(): void
    {
        try {
            app(SaveProduct::class)->handle($this->actionPayload(null, ['visibility' => 'draft']));
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('visibility', $exception->errors());
        }

        $product = new Product;
        $product->fill([
            ...$this->actionPayload(),
            'sellable_quantity' => 99,
            'damaged_quantity' => 99,
            'sold_quantity' => 99,
            'low_stock_threshold' => 99,
        ]);
        $this->assertNull($product->getAttribute('sellable_quantity'));
        $this->assertNull($product->getAttribute('sold_quantity'));
        $this->assertNull($product->getAttribute('low_stock_threshold'));
    }

    public function test_admin_index_escapes_dynamic_content_and_avoids_n_plus_one(): void
    {
        Product::factory()->count(10)->create();
        Product::factory()->create(['name' => '<script>alert(1)</script>', 'sku' => 'ESCAPE-1', 'slug' => 'escape-1']);
        $this->actingAs($this->admin());
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->get(route('admin.products.index'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);

        $this->assertLessThanOrEqual(8, count(DB::getQueryLog()));
        $this->assertStringNotContainsString('Thêm vào giỏ', $response->getContent());
    }

    public function test_locked_inactive_and_invalid_admin_accounts_fail_safely(): void
    {
        foreach ([User::factory()->admin()->locked()->create(), User::factory()->admin()->inactive()->create()] as $admin) {
            $this->actingAs($admin)->get(route('admin.products.index'))->assertRedirect(route('login'));
            $this->assertGuest();
        }

        $invalid = $this->admin();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        DB::table('users')->where('id', $invalid->id)->update(['role' => 'unknown']);
        DB::statement('PRAGMA ignore_check_constraints = OFF');
        $this->actingAs($invalid)->get(route('admin.products.index'))->assertForbidden();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function payload(array $overrides = []): array
    {
        return [
            'category_id' => Category::factory()->create()->id,
            'brand_id' => Brand::factory()->create()->id,
            'name' => 'Laptop Stand Pro',
            'slug' => 'laptop-stand-pro',
            'sku' => 'STAND-PRO-01',
            'short_description' => 'Giá đỡ laptop chắc chắn.',
            'description' => 'Mô tả chi tiết cho sản phẩm phụ kiện laptop.',
            'price_vnd' => 450000,
            'sale_price_vnd' => 399000,
            'visibility' => ProductVisibility::Active->value,
            ...$overrides,
        ];
    }

    private function actionPayload(?Product $product = null, array $overrides = []): array
    {
        return [
            'category_id' => $product?->category_id ?? Category::factory()->create()->id,
            'brand_id' => $product?->brand_id ?? Brand::factory()->create()->id,
            'name' => 'Action Product',
            'slug' => 'action-product',
            'sku' => 'ACTION-PRODUCT',
            'short_description' => 'Short description',
            'description' => 'Detailed description',
            'price_vnd' => 500000,
            'sale_price_vnd' => null,
            'visibility' => ProductVisibility::Active->value,
            ...$overrides,
        ];
    }
}
