<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManagedOrderReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_employee_routes_have_strict_separate_authorization(): void
    {
        $this->get(route('admin.orders.index'))->assertRedirect(route('login'));
        $this->get(route('employee.orders.index'))->assertRedirect(route('login'));

        $customer = User::factory()->create();
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($customer)->get(route('admin.orders.index'))->assertForbidden();
        $this->get(route('employee.orders.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('employee.orders.index'))->assertOk();
        $this->get(route('admin.orders.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.orders.index'))->assertOk();
        $this->get(route('employee.orders.index'))->assertForbidden();

        foreach ([User::factory()->admin()->locked()->create(), User::factory()->employee()->inactive()->create()] as $blocked) {
            $target = $blocked->isAdmin() ? 'admin.orders.index' : 'employee.orders.index';
            $this->actingAs($blocked)->get(route($target))->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_management_index_searches_filters_sorts_and_paginates_without_wildcard_expansion(): void
    {
        $admin = User::factory()->admin()->create();
        $firstCustomer = User::factory()->create(['name' => 'Nguyễn Minh Anh', 'email' => 'minh@example.test']);
        $secondCustomer = User::factory()->create(['name' => 'Trần Bình', 'email' => 'binh@example.test']);
        $literal = $this->order($firstCustomer, [
            'order_code' => 'OPS-%_LITERAL', 'recipient_name' => 'Người Nhận Một',
            'recipient_email' => 'nguoinhan@example.test',
            'recipient_phone' => '+84 (901) 234-567',
            'recipient_address' => '12 Đường Bàn Phím, Hà Nội',
            'status' => OrderStatus::Delivered,
            'payment_status' => PaymentStatus::Unpaid, 'items_subtotal_vnd' => 100_000,
            'total_vnd' => 130_000, 'created_at' => now()->subDays(2),
        ]);
        $newer = $this->order($secondCustomer, [
            'order_code' => 'OPS-NEWER', 'recipient_name' => 'Người Nhận Hai',
            'items_subtotal_vnd' => 500_000, 'total_vnd' => 530_000, 'created_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.orders.index', ['search' => '%_']))
            ->assertOk()->assertSee($literal->order_code)->assertDontSee($newer->order_code);
        foreach (['84901234567', 'Người Nhận Một', 'minh@example.test', 'nguoinhan@example.test', 'Bàn Phím'] as $search) {
            $this->get(route('admin.orders.index', ['search' => $search]))
                ->assertOk()->assertSee($literal->order_code)->assertDontSee($newer->order_code);
        }
        $this->get(route('admin.orders.index', ['status' => 'da_giao', 'payment_status' => 'chua_thanh_toan', 'payment_method' => 'cod']))
            ->assertOk()->assertSee($literal->order_code)->assertDontSee($newer->order_code);
        $this->get(route('admin.orders.index', ['sort' => 'total_asc']))
            ->assertOk()->assertSeeInOrder([$literal->order_code, $newer->order_code]);
        $this->get(route('admin.orders.index', ['sort' => 'total_vnd desc']))
            ->assertOk()->assertSeeInOrder([$newer->order_code, $literal->order_code]);

        Order::factory()->count(20)->for($firstCustomer, 'customer')->create();
        $this->get(route('admin.orders.index', ['page' => 99, 'search' => 'minh@example.test']))
            ->assertRedirect(route('admin.orders.index', ['search' => 'minh@example.test', 'sort' => 'newest', 'page' => 2]));
        $this->get(route('admin.orders.index', ['unexpected' => 'reflected-value']))
            ->assertOk()->assertDontSee('unexpected')->assertDontSee('reflected-value');
    }

    public function test_managed_detail_separates_customer_recipient_and_renders_real_history_with_actor_fallback(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create(['name' => 'Tài khoản hiện tại', 'email' => 'owner@example.test']);
        $order = $this->order($customer, ['recipient_name' => 'Người nhận snapshot', 'recipient_email' => 'receiver@example.test']);
        $product = Product::factory()->create();
        OrderItem::factory()->for($order)->for($product)->create(['product_name' => 'Chuột snapshot', 'sku' => 'SNAP-MOUSE']);
        $actor = User::factory()->employee()->create(['name' => 'Nhân viên cũ']);
        OrderStatusHistory::factory()->for($order)->create(['actor_id' => $actor->id, 'reason' => '<b>Đã tiếp nhận</b>']);
        DB::table('order_status_histories')->where('actor_id', $actor->id)->update(['actor_id' => null]);

        $this->actingAs($admin)->get(route('admin.orders.show', $order->order_code))
            ->assertOk()
            ->assertSee('Tài khoản hiện tại')->assertSee('owner@example.test')
            ->assertSee('Người nhận snapshot')->assertSee('receiver@example.test')
            ->assertSee('Chuột snapshot')->assertSee('SNAP-MOUSE')
            ->assertSee('Hệ thống hoặc tài khoản không còn tồn tại')
            ->assertSee('&lt;b&gt;Đã tiếp nhận&lt;/b&gt;', false)
            ->assertDontSee('Payment Attempt')->assertDontSee('idempotency_fingerprint')
            ->assertDontSee('Chuyển trạng thái')->assertDontSee('Hủy đơn');

        $employee = User::factory()->employee()->create();
        $this->actingAs($employee)->get(route('employee.orders.show', $order->order_code))
            ->assertOk()->assertSee($order->order_code);

        $this->actingAs($admin);
        $this->get(route('admin.orders.show', '404-CODE'))->assertNotFound();
        $this->get(route('admin.orders.show', (string) $order->id))->assertNotFound();
        $this->get(route('admin.orders.show', 'INVALID CODE'))->assertNotFound();
        $this->get('/admin/orders/INVALID%2FCODE')->assertNotFound();
    }

    public function test_vnpay_order_displays_only_its_linked_payment_attempt(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $attempt = PaymentAttempt::factory()->create([
            'user_id' => $customer->id,
            'status' => PaymentStatus::Paid,
            'verified_at' => now(),
            'gateway_transaction_id' => 'VNP-REAL-TRANSACTION',
            'gateway_result_code' => '00',
            'gateway_transaction_status' => '00',
            'gateway_paid_at' => now(),
            'callback_fingerprint' => hash('sha256', 'managed-order-fixture'),
        ]);
        $order = Order::factory()->forVerifiedAttempt($attempt)->create();

        $this->actingAs($admin)->get(route('admin.orders.show', $order->order_code))
            ->assertOk()->assertSee('Payment Attempt')->assertSee($attempt->gateway_reference)
            ->assertSee(number_format($attempt->amount_vnd, 0, ',', '.').' ₫');
    }

    public function test_management_query_count_is_bounded_and_navigation_active_without_database_badges(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $this->order($customer);
        $this->actingAs($admin);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('admin.orders.index'))->assertOk()
            ->assertSee('href="'.route('admin.orders.index').'"', false)
            ->assertSee('aria-current="page"', false);
        $oneOrderQueries = count(DB::getQueryLog());

        Order::factory()->count(8)->for($customer, 'customer')->create();
        DB::flushQueryLog();
        $this->get(route('admin.orders.index'))->assertOk();
        $this->assertSame($oneOrderQueries, count(DB::getQueryLog()));

        $employee = User::factory()->employee()->create();
        $this->actingAs($employee)->get(route('employee.orders.index'))->assertOk()
            ->assertSee(route('employee.orders.index'))
            ->assertDontSee(route('admin.orders.index'));
    }

    public function test_managed_detail_query_count_does_not_grow_with_items_or_history(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $order = $this->order($customer);
        $product = Product::factory()->create();
        OrderItem::factory()->for($order)->for($product)->create();
        OrderStatusHistory::factory()->for($order)->create();
        $this->actingAs($admin);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('admin.orders.show', $order->order_code))->assertOk();
        $oneLineQueries = count(DB::getQueryLog());

        Product::factory()->count(4)->create()->each(
            fn (Product $extraProduct) => OrderItem::factory()->for($order)->for($extraProduct)->create(),
        );
        OrderStatusHistory::factory()->count(4)->for($order)->create();
        DB::flushQueryLog();
        $this->get(route('admin.orders.show', $order->order_code))->assertOk();
        $this->assertSame($oneLineQueries, count(DB::getQueryLog()));
    }

    /** @param array<string, mixed> $override */
    private function order(User $customer, array $override = []): Order
    {
        return Order::factory()->for($customer, 'customer')->create($override);
    }
}
