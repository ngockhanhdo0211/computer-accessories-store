<?php

namespace Tests\Feature;

use App\Actions\AddCartItem;
use App\Actions\CalculateAvailableStock;
use App\Actions\DeleteProduct;
use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CartManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_active_customer_can_use_cart_routes(): void
    {
        $product = Product::factory()->inStock(5)->create();
        $item = CartItem::factory()->for($product)->create();

        $this->get(route('cart.index'))->assertRedirect(route('login'));
        $this->post(route('cart.items.store', $product), ['quantity' => 1])->assertRedirect(route('login'));
        $this->patch(route('cart.items.update', $item), ['quantity' => 1])->assertRedirect(route('login'));
        $this->delete(route('cart.items.destroy', $item))->assertRedirect(route('login'));

        foreach ([User::factory()->employee()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('cart.index'))->assertForbidden();
            $this->actingAs($user)->post(route('cart.items.store', $product), ['quantity' => 1])->assertForbidden();
        }

        foreach ([User::factory()->locked()->create(), User::factory()->inactive()->create()] as $user) {
            $this->actingAs($user)->get(route('cart.index'))->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_invalid_stored_role_or_status_fails_safely_on_cart_routes(): void
    {
        $invalidRole = User::factory()->create();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        DB::table('users')->where('id', $invalidRole->id)->update(['role' => 'unknown']);
        DB::statement('PRAGMA ignore_check_constraints = OFF');
        $this->actingAs($invalidRole)->get(route('cart.index'))->assertForbidden();

        $invalidStatus = User::factory()->create();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        DB::table('users')->where('id', $invalidStatus->id)->update(['status' => 'unknown']);
        DB::statement('PRAGMA ignore_check_constraints = OFF');
        $this->actingAs($invalidStatus)->get(route('cart.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_add_creates_one_line_and_repeated_add_increments_without_inventory_effects(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->inStock(8)->create([
            'damaged_quantity' => 2,
            'sold_quantity' => 3,
        ]);
        $before = $product->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity']);

        $this->actingAs($customer)->post(route('cart.items.store', $product), ['quantity' => 2])
            ->assertRedirect(route('cart.index'))->assertSessionHas('status');
        $this->actingAs($customer)->post(route('cart.items.store', $product), ['quantity' => 3])
            ->assertRedirect(route('cart.index'));

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', [
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);
        $this->assertSame($before, $product->fresh()->only(array_keys($before)));
        $this->assertSame(0, InventoryTransaction::query()->count());
    }

    public function test_add_revalidates_visibility_and_available_stock(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->inStock(2)->create();

        $this->actingAs($customer)->post(route('cart.items.store', $product), ['quantity' => 3])
            ->assertSessionHasErrors('quantity');
        foreach ([0, -1, '1.5', 4294967296] as $invalidQuantity) {
            $this->actingAs($customer)->post(route('cart.items.store', $product), ['quantity' => $invalidQuantity])
                ->assertSessionHasErrors('quantity');
        }

        $outOfStock = Product::factory()->create(['sellable_quantity' => 0]);
        $this->actingAs($customer)->post(route('cart.items.store', $outOfStock), ['quantity' => 1])
            ->assertSessionHasErrors('quantity');

        $hiddenProduct = Product::factory()->hidden()->inStock(2)->create();
        $hiddenCategory = Product::factory()->for(Category::factory()->hidden(), 'category')->inStock(2)->create();
        $hiddenBrand = Product::factory()->for(Brand::factory()->hidden(), 'brand')->inStock(2)->create();

        foreach ([$hiddenProduct, $hiddenCategory, $hiddenBrand] as $unavailable) {
            $this->actingAs($customer)->post(route('cart.items.store', $unavailable), ['quantity' => 1])
                ->assertSessionHasErrors('product');
        }

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_add_ignores_client_owned_fields_and_never_creates_reservation(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->inStock(3)->create([
            'price_vnd' => 350000,
            'sale_price_vnd' => null,
        ]);

        $this->actingAs($customer)->post(route('cart.items.store', $product), [
            'quantity' => 1,
            'user_id' => $other->id,
            'price_vnd' => 1,
            'subtotal_vnd' => 1,
            'role' => 'admin',
        ])->assertRedirect(route('cart.index'));

        $this->assertDatabaseHas('cart_items', [
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseMissing('cart_items', ['user_id' => $other->id]);
        $this->assertSame(350000, $product->fresh()->price_vnd);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_update_and_remove_require_ownership_and_positive_available_quantity(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->inStock(4)->create();
        $item = CartItem::factory()->for($owner)->for($product)->create(['quantity' => 2]);

        $this->actingAs($other)->patch(route('cart.items.update', $item), ['quantity' => 1])->assertForbidden();
        $this->actingAs($other)->delete(route('cart.items.destroy', $item))->assertForbidden();

        $this->actingAs($owner)->patch(route('cart.items.update', $item), [
            'quantity' => 5,
            'cart_item_id' => 999999,
        ])->assertSessionHasErrors('quantity')
            ->assertSessionHasInput('cart_item_id', $item->id);
        $this->actingAs($owner)->get(route('cart.index'))
            ->assertSee("quantity-{$item->id}", false)
            ->assertSee('Chỉ còn 4 sản phẩm có thể giữ trong giỏ.');
        $this->actingAs($owner)->patch(route('cart.items.update', $item), ['quantity' => 0])
            ->assertSessionHasErrors('quantity');
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 2]);

        $this->actingAs($owner)->patch(route('cart.items.update', $item), ['quantity' => 3])
            ->assertRedirect(route('cart.index'));
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 3]);

        $this->actingAs($owner)->delete(route('cart.items.destroy', $item))
            ->assertRedirect(route('cart.index'))->assertSessionHas('status');
        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    public function test_cart_reprices_from_current_product_price_and_uses_integer_total(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->inStock(10)->create([
            'price_vnd' => 500000,
            'sale_price_vnd' => 450000,
        ]);
        CartItem::factory()->for($customer)->for($product)->create(['quantity' => 2]);

        $this->actingAs($customer)->get(route('cart.index'))->assertOk()
            ->assertSee('900.000 ₫');

        $product->update(['sale_price_vnd' => 400000]);

        $response = $this->actingAs($customer)->get(route('cart.index'))->assertOk()
            ->assertSee('400.000 ₫ / sản phẩm')
            ->assertSee('800.000 ₫');
        $this->assertSame(800000, $response->viewData('total_vnd'));
    }

    public function test_hidden_or_insufficient_line_is_visible_but_excluded_from_total(): void
    {
        $customer = User::factory()->create();
        $hidden = Product::factory()->hidden()->inStock(4)->create(['price_vnd' => 100000, 'sale_price_vnd' => null]);
        $short = Product::factory()->inStock(1)->create(['price_vnd' => 200000, 'sale_price_vnd' => null]);
        CartItem::factory()->for($customer)->for($hidden)->create(['quantity' => 1]);
        CartItem::factory()->for($customer)->for($short)->create(['quantity' => 2]);

        $response = $this->actingAs($customer)->get(route('cart.index'))->assertOk()
            ->assertSee($hidden->name)
            ->assertSee($short->name)
            ->assertSee('Sản phẩm đã bị ẩn')
            ->assertSee('Chỉ còn 1 sản phẩm khả dụng');

        $this->assertSame(0, $response->viewData('total_vnd'));

        $this->actingAs($customer)->patch(route('cart.items.update', $hidden->cartItems()->first()), ['quantity' => 1])
            ->assertSessionHasErrors('product');
        $hiddenItem = $hidden->cartItems()->firstOrFail();
        $this->actingAs($customer)->delete(route('cart.items.destroy', $hiddenItem))
            ->assertRedirect(route('cart.index'));
        $this->assertDatabaseMissing('cart_items', ['id' => $hiddenItem->id]);
    }

    public function test_empty_cart_header_count_and_product_detail_cta_are_role_aware(): void
    {
        $customer = User::factory()->create();
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->inStock(3)->create();

        $this->actingAs($customer)->get(route('cart.index'))->assertOk()
            ->assertSee('Giỏ hàng đang trống')
            ->assertSee('Giỏ hàng (0)');
        $this->actingAs($customer)->get(route('products.show', $product))->assertOk()
            ->assertSee('Thêm vào giỏ')
            ->assertSee(route('cart.items.store', $product), false);

        auth()->logout();
        $this->get(route('products.show', $product))->assertSee('Đăng nhập để thêm vào giỏ');
        foreach ([$employee, $admin] as $user) {
            $this->actingAs($user)->get(route('products.show', $product))->assertOk()
                ->assertDontSee('Thêm vào giỏ')
                ->assertDontSee('Đăng nhập để thêm vào giỏ')
                ->assertDontSee(route('cart.index'), false);
        }
    }

    public function test_header_count_is_real_line_count_and_cart_links_to_checkout_quote(): void
    {
        $customer = User::factory()->create();
        foreach (Product::factory()->count(2)->inStock(10)->create() as $product) {
            CartItem::factory()->for($customer)->for($product)->create();
        }

        $this->actingAs($customer)->get(route('cart.index'))->assertOk()
            ->assertSee('Giỏ hàng (2)')
            ->assertSee('name="_token"', false)
            ->assertSee('name="_method" value="PATCH"', false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertSee(route('checkout.show'), false)
            ->assertDontSee('Thanh toán');
    }

    public function test_cart_escapes_product_content_and_has_bounded_queries(): void
    {
        $customer = User::factory()->create();
        $products = Product::factory()->count(10)->inStock(10)->create();
        foreach ($products as $index => $product) {
            $product->update(['name' => $index === 0 ? '<script>alert(1)</script>' : "Product {$index}"]);
            CartItem::factory()->for($customer)->for($product)->create(['quantity' => 1]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($customer)->get(route('cart.index'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
        $this->assertLessThanOrEqual(10, count(DB::getQueryLog()));
        $this->assertCount(10, $response->viewData('items'));
    }

    public function test_availability_formula_is_non_negative_and_batch_safe(): void
    {
        $first = Product::factory()->make(['id' => 10, 'sellable_quantity' => 7]);
        $second = Product::factory()->make(['id' => 20, 'sellable_quantity' => 1]);
        $availability = app(CalculateAvailableStock::class);

        $this->assertSame(4, $availability->forProduct($first, 3));
        $this->assertSame(0, $availability->forProduct($second, 5));
        $this->assertSame([10 => 5, 20 => 1], $availability->forProducts([$first, $second], [10 => 2]));
    }

    public function test_actions_reject_non_customer_and_direct_overstock_calls(): void
    {
        $employee = User::factory()->employee()->create();
        $customer = User::factory()->create();
        $product = Product::factory()->inStock(1)->create();
        $action = app(AddCartItem::class);

        try {
            $action->handle($employee, $product, 1);
            $this->fail('Employee action call was accepted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('cart_items', 0);
        }

        try {
            $action->handle($customer, $product, 2);
            $this->fail('Overstock action call was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
            $this->assertDatabaseCount('cart_items', 0);
        }
    }

    public function test_cart_routes_have_expected_methods(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        $this->assertSame(['GET', 'HEAD'], $routes->firstWhere('action.as', 'cart.index')->methods());
        $this->assertSame(['POST'], $routes->firstWhere('action.as', 'cart.items.store')->methods());
        $this->assertSame(['PATCH'], $routes->firstWhere('action.as', 'cart.items.update')->methods());
        $this->assertSame(['DELETE'], $routes->firstWhere('action.as', 'cart.items.destroy')->methods());
    }

    public function test_money_rules_cover_current_price_exact_totals_and_overflow_safely(): void
    {
        $customer = User::factory()->create();
        $regular = Product::factory()->inStock(3)->create([
            'price_vnd' => 125000,
            'sale_price_vnd' => null,
        ]);
        $sale = Product::factory()->inStock(3)->create([
            'price_vnd' => 200000,
            'sale_price_vnd' => 150000,
        ]);
        CartItem::factory()->for($customer)->for($regular)->create(['quantity' => 2]);
        CartItem::factory()->for($customer)->for($sale)->create(['quantity' => 3]);

        $response = $this->actingAs($customer)->get(route('cart.index'))->assertOk();
        $this->assertSame(700000, $response->viewData('total_vnd'));
        $this->assertFalse($response->viewData('total_overflow'));

        CartItem::query()->delete();
        $largePrice = intdiv(PHP_INT_MAX, 2) + 1;
        $first = Product::factory()->inStock(1)->create(['price_vnd' => $largePrice, 'sale_price_vnd' => null]);
        $second = Product::factory()->inStock(1)->create(['price_vnd' => $largePrice, 'sale_price_vnd' => null]);
        CartItem::factory()->for($customer)->for($first)->create(['quantity' => 1]);
        CartItem::factory()->for($customer)->for($second)->create(['quantity' => 1]);

        $overflow = $this->actingAs($customer)->get(route('cart.index'))->assertOk()
            ->assertSee('Vượt giới hạn hỗ trợ')
            ->assertSee('Tổng giỏ hàng quá lớn');
        $this->assertNull($overflow->viewData('total_vnd'));
        $this->assertTrue($overflow->viewData('total_overflow'));
    }

    public function test_line_subtotal_overflow_is_rejected_on_write_and_safe_on_legacy_read(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->inStock(2)->create([
            'price_vnd' => intdiv(PHP_INT_MAX, 2) + 1,
            'sale_price_vnd' => null,
        ]);

        $this->actingAs($customer)->post(route('cart.items.store', $product), ['quantity' => 2])
            ->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('cart_items', 0);

        CartItem::factory()->for($customer)->for($product)->create(['quantity' => 2]);
        $response = $this->actingAs($customer)->get(route('cart.index'))->assertOk()
            ->assertSee('Giá trị dòng giỏ vượt giới hạn hỗ trợ')
            ->assertSee('Không thể tính');
        $this->assertSame(0, $response->viewData('total_vnd'));
        $this->assertFalse($response->viewData('total_overflow'));
        $this->assertFalse($response->viewData('items')->first()['isPurchasable']);
    }

    public function test_out_of_stock_detail_has_no_cart_or_login_cta(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 0]);

        $this->get(route('products.show', $product))->assertOk()
            ->assertSee('Tạm hết hàng')
            ->assertDontSee('Đăng nhập để thêm vào giỏ')
            ->assertDontSee(route('cart.items.store', $product), false);

        $customer = User::factory()->create();
        $this->actingAs($customer)->get(route('products.show', $product))->assertOk()
            ->assertDontSee('Thêm vào giỏ')
            ->assertDontSee(route('cart.items.store', $product), false);
    }

    public function test_header_cart_query_is_role_aware_and_cart_page_reuses_summary_count(): void
    {
        $customer = User::factory()->create();
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->inStock(2)->create();
        CartItem::factory()->for($customer)->for($product)->create();

        auth()->logout();
        $this->assertSame(0, $this->cartQueryCount(fn () => $this->get(route('home'))->assertOk()));
        $this->assertSame(1, $this->cartQueryCount(fn () => $this->actingAs($customer)->get(route('home'))->assertOk()));
        $this->assertSame(1, $this->cartQueryCount(fn () => $this->actingAs($customer)->get(route('cart.index'))->assertOk()));
        $this->assertSame(0, $this->cartQueryCount(fn () => $this->actingAs($employee)->get(route('employee.dashboard'))->assertOk()));
        $this->assertSame(0, $this->cartQueryCount(fn () => $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()));
    }

    public function test_numeric_product_slug_routes_to_add_and_cart_fk_blocks_product_delete(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->inStock(2)->create(['slug' => '123456']);

        $this->actingAs($customer)->post(route('cart.items.store', $product), ['quantity' => 1])
            ->assertRedirect(route('cart.index'));
        $this->assertDatabaseHas('cart_items', ['user_id' => $customer->id, 'product_id' => $product->id]);

        try {
            app(DeleteProduct::class)->handle($product);
            $this->fail('Referenced Product was deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product', $exception->errors());
            $this->assertDatabaseHas('products', ['id' => $product->id]);
            $this->assertDatabaseHas('cart_items', ['product_id' => $product->id]);
        }
    }

    public function test_validation_rejects_boolean_scientific_and_array_quantities(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->inStock(20)->create();

        foreach ([true, '1e1', ['1']] as $quantity) {
            $this->actingAs($customer)->post(route('cart.items.store', $product), ['quantity' => $quantity])
                ->assertSessionHasErrors('quantity');
        }

        $this->assertDatabaseCount('cart_items', 0);
    }

    private function cartQueryCount(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $queries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'cart_items'))
            ->count();
        DB::disableQueryLog();

        return $queries;
    }
}
