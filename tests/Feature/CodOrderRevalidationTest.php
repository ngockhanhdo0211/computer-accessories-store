<?php

namespace Tests\Feature;

use App\Enums\MembershipLevel;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CodOrderRevalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_visibility_and_cart_quantity_are_revalidated_after_quote(): void
    {
        foreach (['product', 'category', 'brand', 'quantity'] as $case) {
            $customer = User::factory()->create();
            $product = Product::factory()->inStock(10)->create();
            $item = CartItem::factory()->for($customer)->for($product)->create(['quantity' => 1]);
            $key = $this->quote($customer);

            match ($case) {
                'product' => $product->update(['visibility' => 'hidden']),
                'category' => $product->category->update(['is_visible' => false]),
                'brand' => $product->brand->update(['is_visible' => false]),
                'quantity' => $item->update(['quantity' => 2]),
            };

            $this->post(route('checkout.cod.store'), $this->payload($key))
                ->assertRedirect(route('checkout.show'))
                ->assertSessionHasErrors($case === 'quantity' ? 'request_key' : 'cart');
            $this->assertSame(0, Order::query()->where('user_id', $customer->id)->count());
            $this->assertDatabaseHas('cart_items', ['id' => $item->id]);
        }
    }

    public function test_coupon_definition_target_window_and_membership_are_revalidated_after_quote(): void
    {
        foreach (['definition', 'target', 'inactive', 'expired', 'upcoming', 'tier'] as $case) {
            $customer = User::factory()->create([
                'current_tier' => $case === 'tier' ? MembershipLevel::Vang : MembershipLevel::Dong,
            ]);
            $product = Product::factory()->inStock(10)->create();
            CartItem::factory()->for($customer)->for($product)->create();
            $coupon = Coupon::factory()->create(array_filter([
                'scope' => $case === 'target' ? 'product' : null,
                'required_tier' => $case === 'tier' ? MembershipLevel::Vang : null,
            ], fn ($value): bool => $value !== null));
            if ($case === 'target') {
                $coupon->products()->attach($product);
            }
            $key = $this->quote($customer, $coupon->code);

            match ($case) {
                'definition' => $coupon->update(['value' => 25]),
                'target' => $coupon->products()->detach($product),
                'inactive' => $coupon->update(['is_active' => false]),
                'expired' => $coupon->update(['ends_at' => now()->subSecond()]),
                'upcoming' => $coupon->update(['starts_at' => now()->addHour(), 'ends_at' => now()->addDay()]),
                'tier' => $customer->forceFill(['current_tier' => MembershipLevel::Dong])->save(),
            };

            if ($case === 'tier') {
                $this->assertSame('dong', $customer->fresh()->getRawOriginal('current_tier'));
                $this->assertSame('vang', $coupon->fresh()->getRawOriginal('required_tier'));
            }

            $response = $this->post(route('checkout.cod.store'), $this->payload($key, $coupon->code));
            $this->assertTrue($response->isRedirect(route('checkout.show')), 'COD accepted stale '.$case.' coupon data.');
            $response->assertSessionHasErrors($case === 'definition' ? 'request_key' : 'coupon_code');
            $this->assertSame(0, Order::query()->where('user_id', $customer->id)->count());
            $this->assertDatabaseHas('cart_items', ['user_id' => $customer->id, 'product_id' => $product->id]);
        }
    }

    public function test_coupon_target_swap_with_the_same_eligible_total_invalidates_the_fingerprint(): void
    {
        $customer = User::factory()->create();
        $first = Product::factory()->inStock(10)->create(['price_vnd' => 100_000]);
        $second = Product::factory()->inStock(10)->create(['price_vnd' => 100_000]);
        CartItem::factory()->for($customer)->for($first)->create(['quantity' => 1]);
        CartItem::factory()->for($customer)->for($second)->create(['quantity' => 1]);
        $coupon = Coupon::factory()->create(['scope' => 'product']);
        $coupon->products()->attach($first);
        $key = $this->quote($customer, $coupon->code);

        $coupon->products()->detach($first);
        $coupon->products()->attach($second);

        $this->post(route('checkout.cod.store'), $this->payload($key, $coupon->code))
            ->assertRedirect(route('checkout.show'))
            ->assertSessionHasErrors('request_key');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 2);
    }

    private function quote(User $customer, ?string $couponCode = null): string
    {
        return $this->actingAs($customer)
            ->post(route('checkout.quote'), $this->payload(null, $couponCode))
            ->assertOk()
            ->viewData('requestKey');
    }

    /** @return array<string, mixed> */
    private function payload(?string $requestKey = null, ?string $couponCode = null): array
    {
        return [
            'request_key' => $requestKey,
            'recipient_name' => 'Nguyen Minh Anh',
            'recipient_email' => 'receiver@example.test',
            'recipient_phone' => '0912345678',
            'province' => 'Ha Noi',
            'district' => 'Cau Giay',
            'ward' => 'Dich Vong',
            'address_line' => '12 Tran Thai Tong',
            'coupon_code' => $couponCode,
        ];
    }
}
