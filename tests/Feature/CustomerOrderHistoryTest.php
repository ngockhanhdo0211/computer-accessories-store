<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerOrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_routes_enforce_auth_role_active_status_and_invalid_role_safely(): void
    {
        $this->get(route('orders.index'))->assertRedirect(route('login'));

        foreach ([User::factory()->employee()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('orders.index'))->assertForbidden();
        }

        foreach ([User::factory()->locked()->create(), User::factory()->inactive()->create()] as $user) {
            $this->actingAs($user)->get(route('orders.index'))->assertRedirect(route('login'));
            $this->assertGuest();
        }

        $invalid = User::factory()->create();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            DB::table('users')->where('id', $invalid->id)->update(['role' => 'unexpected']);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $this->actingAs($invalid)->get(route('orders.index'))->assertForbidden();
    }

    public function test_customer_index_is_owned_and_distinguishes_empty_from_no_result(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $own = $this->order($customer, ['order_code' => 'ORD-MINE']);
        $foreign = $this->order($other, ['order_code' => 'ORD-FOREIGN']);

        $this->actingAs($customer)->get(route('orders.index'))
            ->assertOk()
            ->assertSee($own->order_code)
            ->assertDontSee($foreign->order_code)
            ->assertSee('Đơn hàng của tôi');

        $this->actingAs(User::factory()->create())->get(route('orders.index'))
            ->assertOk()->assertSee('Bạn chưa có đơn hàng nào');

        $this->actingAs($customer)->get(route('orders.index', ['search' => 'missing']))
            ->assertOk()->assertSee('Không tìm thấy đơn hàng phù hợp')->assertSee('Xóa bộ lọc');
    }

    public function test_customer_search_filters_sort_and_page_bounds_are_safe_and_stable(): void
    {
        $customer = User::factory()->create();
        $literal = $this->order($customer, [
            'order_code' => 'ORD-100%_SAFE',
            'status' => OrderStatus::Delivered,
            'payment_status' => PaymentStatus::Unpaid,
            'payment_method' => PaymentMethod::CashOnDelivery,
            'total_vnd' => 130_000,
            'items_subtotal_vnd' => 100_000,
            'created_at' => now()->subDays(2),
        ]);
        $newer = $this->order($customer, ['order_code' => 'ORD-NEWER', 'total_vnd' => 530_000, 'items_subtotal_vnd' => 500_000, 'created_at' => now()]);

        $this->actingAs($customer)->get(route('orders.index', ['search' => '%_']))
            ->assertOk()->assertSee($literal->order_code)->assertDontSee($newer->order_code);
        $this->get(route('orders.index', ['status' => 'da_giao', 'payment_status' => 'chua_thanh_toan', 'payment_method' => 'cod']))
            ->assertOk()->assertSee($literal->order_code)->assertDontSee($newer->order_code);
        $this->get(route('orders.index', ['date_from' => now()->subDays(3)->format('Y-m-d'), 'date_to' => now()->subDay()->format('Y-m-d')]))
            ->assertOk()->assertSee($literal->order_code)->assertDontSee($newer->order_code);
        $this->get(route('orders.index', ['sort' => 'total_asc']))
            ->assertOk()->assertSeeInOrder([$literal->order_code, $newer->order_code]);
        $this->get(route('orders.index', ['sort' => 'total_vnd desc; drop table orders']))
            ->assertOk()->assertSeeInOrder([$newer->order_code, $literal->order_code]);
        $this->get(route('orders.index', ['search' => '   ']))
            ->assertOk()->assertSee($literal->order_code)->assertSee($newer->order_code);

        Order::factory()->count(19)->for($customer, 'customer')->create();
        $this->get(route('orders.index', ['page' => 99, 'status' => 'da_dat']))
            ->assertRedirect(route('orders.index', ['status' => 'da_dat', 'sort' => 'newest', 'page' => 1]));
        $this->get(route('orders.index', ['unexpected' => 'reflected-value']))
            ->assertOk()->assertDontSee('unexpected')->assertDontSee('reflected-value');
    }

    public function test_filter_validation_is_visible_and_preserves_the_invalid_value(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->from(route('orders.index'))->followingRedirects()->get(route('orders.index', [
            'date_from' => '2026-09-30',
            'date_to' => '2026-09-01',
        ]))
            ->assertOk()
            ->assertSee('Ngày kết thúc phải bằng hoặc sau ngày bắt đầu.')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('value="2026-09-01"', false);
    }

    public function test_customer_detail_uses_snapshots_is_escaped_and_blocks_idor_and_numeric_confusion(): void
    {
        $customer = User::factory()->create(['name' => 'Tên hiện tại']);
        $other = User::factory()->create();
        $coupon = Coupon::factory()->create(['code' => 'HISTORY10']);
        $order = $this->order($customer, [
            'order_code' => '123456',
            'recipient_name' => '<script>alert(1)</script>',
            'coupon_id' => $coupon->id,
            'coupon_snapshot_json' => [
                'coupon_id' => $coupon->id, 'code' => 'HISTORY10', 'type' => 'fixed',
                'scope' => 'cart', 'value' => 10_000, 'eligible_subtotal_vnd' => 200_000,
            ],
            'item_discount_vnd' => 10_000,
            'total_vnd' => 220_000,
        ]);
        $product = Product::factory()->create(['name' => 'Tên mới', 'price_vnd' => 999_000]);
        OrderItem::factory()->for($order)->for($product)->create(['product_name' => '<b>Bàn phím lịch sử</b>', 'sku' => 'OLD-SKU']);
        OrderStatusHistory::factory()->for($order)->create(['actor_id' => $customer->id]);

        $product->update(['name' => 'Catalog đã đổi', 'price_vnd' => 1_500_000]);
        $coupon->update(['code' => 'CHANGED']);
        $customer->update(['name' => 'Khách hàng đã đổi']);

        $this->actingAs($customer)->get(route('orders.show', '123456'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;b&gt;Bàn phím lịch sử&lt;/b&gt;', false)
            ->assertSee('OLD-SKU')->assertSee('HISTORY10')
            ->assertDontSee('Catalog đã đổi')->assertDontSee('CHANGED')
            ->assertDontSee('idempotency_fingerprint')->assertDontSee('request_key')
            ->assertDontSee('Hủy đơn')->assertDontSee('Thanh toán ngay')->assertDontSee('Viết đánh giá');

        $this->actingAs($other)->get(route('orders.show', $order->order_code))->assertNotFound();
        $this->get(route('orders.show', (string) $order->id))->assertNotFound();
        $this->get(route('orders.show', 'NOT-FOUND'))->assertNotFound();

        $encodedUrl = route('orders.show', 'INVALID CODE');
        $this->assertStringContainsString('INVALID%20CODE', $encodedUrl);
        $this->get($encodedUrl)->assertNotFound();
        $this->get('/orders/INVALID%2FCODE')->assertNotFound();
        $this->get('/orders/'.str_repeat('A', 41))->assertNotFound();
    }

    public function test_customer_index_query_count_is_bounded_and_navigation_is_role_aware(): void
    {
        $customer = User::factory()->create();
        $this->order($customer);
        $this->actingAs($customer);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('orders.index'))->assertOk()->assertSee('Đơn hàng của tôi');
        $oneOrderQueries = count(DB::getQueryLog());

        Order::factory()->count(8)->for($customer, 'customer')->create();
        DB::flushQueryLog();
        $this->get(route('orders.index'))->assertOk();
        $this->assertSame($oneOrderQueries, count(DB::getQueryLog()));

        $this->actingAs(User::factory()->admin()->create())->get(route('home'))->assertDontSee('Đơn hàng của tôi');
        $this->actingAs(User::factory()->employee()->create())->get(route('home'))->assertDontSee('Đơn hàng của tôi');
        auth()->logout();
        $this->get(route('home'))->assertDontSee('Đơn hàng của tôi');
    }

    /** @param array<string, mixed> $override */
    private function order(User $customer, array $override = []): Order
    {
        return Order::factory()->for($customer, 'customer')->create($override);
    }
}
