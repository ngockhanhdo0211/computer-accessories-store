<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_every_authenticated_role_can_open_catalog(): void
    {
        $this->get(route('products.index'))->assertOk()->assertSee('Catalog phụ kiện');

        foreach ([User::factory()->create(), User::factory()->employee()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('products.index'))->assertOk();
        }
    }

    public function test_catalog_only_lists_products_eligible_through_all_three_visibility_rules(): void
    {
        $eligible = Product::factory()->create(['name' => 'Eligible Product']);
        $hidden = Product::factory()->hidden()->create(['name' => 'Hidden Product']);
        $hiddenCategory = Product::factory()->for(Category::factory()->hidden(), 'category')->create(['name' => 'Hidden Category Product']);
        $hiddenBrand = Product::factory()->for(Brand::factory()->hidden(), 'brand')->create(['name' => 'Hidden Brand Product']);

        $response = $this->get(route('products.index'))->assertOk()->assertSee($eligible->name);
        foreach ([$hidden, $hiddenCategory, $hiddenBrand] as $product) {
            $response->assertDontSee($product->name);
            $this->get(route('products.show', $product))->assertNotFound();
        }
        $this->get(route('products.show', $eligible))->assertOk()->assertSee($eligible->sku);
    }

    public function test_search_and_category_brand_filters_return_only_matching_public_products(): void
    {
        $categoryA = Category::factory()->create();
        $categoryB = Category::factory()->create();
        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();
        $mouse = Product::factory()->for($categoryA)->for($brandA)->create([
            'name' => 'Silent Mouse', 'sku' => 'MOUSE-SILENT', 'slug' => 'silent-mouse',
        ]);
        $hub = Product::factory()->for($categoryB)->for($brandB)->create([
            'name' => 'USB Hub', 'sku' => 'HUB-7IN1', 'slug' => 'usb-hub',
        ]);

        $this->get(route('products.index', ['search' => 'silent']))->assertSee($mouse->name)->assertDontSee($hub->name);
        $this->get(route('products.index', ['search' => 'hub-7in1']))->assertSee($hub->name)->assertDontSee($mouse->name);
        $this->get(route('products.index', ['category' => $categoryA->id]))->assertSee($mouse->name)->assertDontSee($hub->name);
        $this->get(route('products.index', ['brand' => $brandB->id]))->assertSee($hub->name)->assertDontSee($mouse->name);
    }

    public function test_all_catalog_sorts_are_stable_and_use_effective_price(): void
    {
        $old = Product::factory()->create([
            'name' => 'Zulu', 'price_vnd' => 500000, 'sale_price_vnd' => 100000,
            'created_at' => Carbon::parse('2026-01-01'), 'updated_at' => Carbon::parse('2026-01-01'),
        ]);
        $middle = Product::factory()->create([
            'name' => 'Beta', 'price_vnd' => 200000, 'sale_price_vnd' => null,
            'created_at' => Carbon::parse('2026-02-01'), 'updated_at' => Carbon::parse('2026-02-01'),
        ]);
        $new = Product::factory()->create([
            'name' => 'Alpha', 'price_vnd' => 300000, 'sale_price_vnd' => null,
            'created_at' => Carbon::parse('2026-03-01'), 'updated_at' => Carbon::parse('2026-03-01'),
        ]);

        $this->assertSame([$old->id, $middle->id, $new->id], $this->idsFor('price_asc'));
        $this->assertSame([$new->id, $middle->id, $old->id], $this->idsFor('price_desc'));
        $this->assertSame([$new->id, $middle->id, $old->id], $this->idsFor('newest'));
        $this->assertSame([$new->id, $middle->id, $old->id], $this->idsFor('name'));
    }

    public function test_search_treats_sql_wildcards_as_literal_characters(): void
    {
        $literal = Product::factory()->create([
            'name' => 'Adapter 100%_USB',
            'sku' => 'ADAPTER-100-PERCENT',
        ]);
        $other = Product::factory()->create([
            'name' => 'Ordinary Adapter',
            'sku' => 'OTHER-ADAPTER',
        ]);

        $this->get(route('products.index', ['search' => '%_']))
            ->assertOk()
            ->assertSee($literal->name)
            ->assertDontSee($other->name);
    }

    public function test_invalid_query_parameters_are_ignored_without_sql_injection(): void
    {
        $product = Product::factory()->create(['name' => 'Safe Product']);

        $response = $this->get(route('products.index', [
            'sort' => 'price_vnd desc; drop table products;--',
            'category' => '1 OR 1=1',
            'brand' => '../1',
        ]));

        $response->assertOk()->assertSee($product->name);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertSame('newest', $response->viewData('filters')['sort']);
        $this->assertNull($response->viewData('filters')['category']);
        $this->assertNull($response->viewData('filters')['brand']);
    }

    public function test_pagination_preserves_query_and_redirects_out_of_range_page(): void
    {
        Product::factory()->count(21)->create(['name' => 'Product']);

        $response = $this->get(route('products.index', ['search' => 'Product', 'sort' => 'name']))->assertOk();
        $products = $response->viewData('products');
        $this->assertCount(20, $products->items());
        $this->assertStringContainsString('search=Product', $products->url(2));
        $this->assertStringContainsString('sort=name', $products->url(2));
        $this->get(route('products.index', ['sort' => 'name', 'page' => 999]))
            ->assertRedirect(route('products.index', ['sort' => 'name', 'page' => 2]));
    }

    public function test_cards_format_vnd_and_show_real_stock_and_honest_placeholder(): void
    {
        $inStock = Product::factory()->create([
            'name' => 'In Stock', 'price_vnd' => 1250000, 'sale_price_vnd' => null, 'sellable_quantity' => 3,
        ]);
        $out = Product::factory()->create([
            'name' => 'Out Of Stock', 'price_vnd' => 500000, 'sale_price_vnd' => 450000, 'sellable_quantity' => 0,
        ]);

        $this->get(route('products.index'))->assertOk()
            ->assertSee($inStock->name)->assertSee('1.250.000 ₫')
            ->assertSee($out->name)->assertSee('450.000 ₫')
            ->assertSee('Còn hàng')->assertSee('Tạm hết hàng')
            ->assertSee('Chưa có ảnh')
            ->assertDontSee('Thêm vào giỏ')
            ->assertDontSee('rating', false)
            ->assertDontSee('Đã bán');
    }

    public function test_product_detail_renders_gallery_in_order_primary_first_and_escaped_plain_text(): void
    {
        $product = Product::factory()->create([
            'name' => '<script>alert(1)</script>',
            'short_description' => '<b>Plain summary</b>',
            'description' => "First line\nSecond <script>alert(2)</script>",
            'sellable_quantity' => 1,
        ]);
        ProductImage::factory()->for($product)->create([
            'path' => 'products/'.$product->id.'/second.jpg', 'alt_text' => 'Second image', 'sort_order' => 1, 'is_primary' => false,
        ]);
        ProductImage::factory()->for($product)->primary()->create([
            'path' => 'products/'.$product->id.'/primary.jpg', 'alt_text' => 'Primary image', 'sort_order' => 0,
        ]);

        $response = $this->get(route('products.show', $product))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;b&gt;Plain summary&lt;/b&gt;', false)
            ->assertSee('&lt;script&gt;alert(2)&lt;/script&gt;', false)
            ->assertSee('alt="Primary image"', false)
            ->assertSee('alt="Second image"', false)
            ->assertSee('Còn hàng')
            ->assertDontSee('Thêm vào giỏ')
            ->assertDontSee('Mua ngay');

        $html = $response->getContent();
        $this->assertLessThan(strpos($html, 'Second image'), strpos($html, 'Primary image'));
    }

    public function test_catalog_navigation_is_available_to_all_roles_and_cart_is_customer_only(): void
    {
        $guest = $this->get(route('home'))->assertOk()
            ->assertSee('href="'.route('products.index').'"', false)
            ->assertDontSee('href="'.route('cart.index').'"', false);

        $customer = User::factory()->create();
        $this->actingAs($customer)->get(route('home'))->assertOk()
            ->assertSee('href="'.route('cart.index').'"', false);

        foreach ([User::factory()->employee()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('home'))->assertOk()
                ->assertSee('href="'.route('products.index').'"', false)
                ->assertDontSee('href="'.route('cart.index').'"', false)
                ->assertDontSee('href="'.url('/checkout').'"', false);
        }
    }

    public function test_catalog_and_detail_avoid_n_plus_one_queries(): void
    {
        $products = Product::factory()->count(10)->create();
        foreach ($products as $product) {
            ProductImage::factory()->for($product)->primary()->create();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('products.index'))->assertOk();
        $this->assertLessThanOrEqual(8, count(DB::getQueryLog()));

        DB::flushQueryLog();
        $this->get(route('products.show', $products->first()))->assertOk();
        $this->assertLessThanOrEqual(5, count(DB::getQueryLog()));
    }

    public function test_empty_and_no_result_states_are_distinct_and_filter_options_are_public_only(): void
    {
        $visibleCategory = Category::factory()->create(['name' => 'Visible Category']);
        $hiddenCategory = Category::factory()->hidden()->create(['name' => 'Hidden Category']);
        $visibleBrand = Brand::factory()->create(['name' => 'Visible Brand']);
        $hiddenBrand = Brand::factory()->hidden()->create(['name' => 'Hidden Brand']);

        $this->get(route('products.index'))->assertOk()
            ->assertSee('Chưa có sản phẩm phù hợp')
            ->assertSee($visibleCategory->name)->assertDontSee($hiddenCategory->name)
            ->assertSee($visibleBrand->name)->assertDontSee($hiddenBrand->name);

        Product::factory()->for($visibleCategory)->for($visibleBrand)->create(['name' => 'Existing Product']);
        $this->get(route('products.index', ['search' => 'missing']))->assertOk()
            ->assertSee('Chưa có sản phẩm phù hợp')->assertDontSee('Existing Product');
    }

    private function idsFor(string $sort): array
    {
        return $this->get(route('products.index', ['sort' => $sort]))
            ->assertOk()->viewData('products')->pluck('id')->all();
    }
}
