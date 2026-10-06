<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_home_is_available_with_only_real_account_destinations(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Trạm Phụ Kiện')
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('href="'.route('register').'"', false)
            ->assertDontSee('href="#"', false)
            ->assertDontSee('name="role"', false)
            ->assertDontSee('name="status"', false);

        preg_match_all('/(?:href|action)="([^"]+)"/', $response->getContent(), $matches);

        foreach ($matches[1] as $target) {
            $this->assertContains($target, ['#main-content', route('home'), route('products.index'), route('login'), route('register')]);
        }
    }

    public function test_home_hero_references_an_existing_local_image(): void
    {
        $imagePath = public_path('images/laptop-accessories-hero.jpg');

        $this->get('/')
            ->assertOk()
            ->assertSee('src="'.asset('images/laptop-accessories-hero.jpg').'"', false)
            ->assertSee('width="1536" height="1024"', false);

        $this->assertFileExists($imagePath);
    }

    public function test_authenticated_home_has_dashboard_and_post_logout(): void
    {
        $user = User::factory()->create(['name' => 'Khách thử nghiệm']);
        $this->actingAs($user);

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee($user->name)
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertSee('href="'.route('cart.index').'"', false)
            ->assertSee('href="'.route('orders.index').'"', false)
            ->assertSee('href="'.route('support.show').'"', false)
            ->assertSee('method="POST" action="'.route('logout').'"', false)
            ->assertDontSee('href="'.route('logout').'"', false)
            ->assertDontSee('href="'.route('register').'"', false);
    }

    public function test_customer_facing_entry_pages_do_not_expose_internal_delivery_language(): void
    {
        $technicalCopy = [
            'Đã triển khai',
            'Chưa triển khai',
            'Foundation',
            'Callback',
            'Writer',
            'Projection',
            'Catalog đang hoàn thiện',
        ];

        foreach (['/', '/login', '/register', '/products'] as $uri) {
            $response = $this->get($uri)->assertOk();

            foreach ($technicalCopy as $copy) {
                $response->assertDontSee($copy);
            }
        }
    }

    public function test_storefront_styles_define_responsive_catalogue_and_purchase_workbench_contracts(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $tokens = file_get_contents(base_path('tokens.css'));

        $this->assertIsString($css);
        $this->assertIsString($tokens);
        $tokens = str_replace("\r\n", "\n", $tokens);
        $this->assertStringContainsString('html, body { overflow-x: clip; }', $css);
        $this->assertStringContainsString('.shop-hero { display: block;', $css);
        $this->assertStringContainsString('.shop-hero__inner { display: grid;', $css);
        $this->assertStringContainsString('.catalog-layout { grid-template-columns: 15rem minmax(0, 1fr);', $css);
        $this->assertStringContainsString('grid-template-columns: repeat(3, minmax(0, 1fr));', $css);
        $this->assertStringContainsString('@media (max-width: 48rem)', $css);
        $this->assertStringContainsString('.storefront-body .support-panel { position: fixed;', $css);
        $this->assertStringContainsString('.storefront-body .page-intro {', $css);
        $this->assertStringContainsString('.storefront-body .order-panel {', $css);
        $this->assertStringContainsString('.storefront-body .support-composer {', $css);
        $this->assertStringNotContainsString("\n  .support-panel { position: fixed;", $css);
        $this->assertStringContainsString("--container: 76rem;\n", $tokens);
        $this->assertStringContainsString(".storefront-body {\n  --color-surface:", $tokens);
        $this->assertStringContainsString("  --container: 82rem;\n", $tokens);
    }

    public function test_customer_accessibility_hooks_remain_connected_after_the_refresh(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->get('/')
            ->assertOk()
            ->assertSee('aria-describedby="support-customer-hint support-customer-error"', false)
            ->assertSee('id="support-customer-hint"', false)
            ->assertSee('id="support-customer-error"', false)
            ->assertSee('data-support-chat', false)
            ->assertSee('data-support-form', false)
            ->assertSee('name="client_message_key"', false);

        $orderView = file_get_contents(resource_path('views/orders/show.blade.php'));
        $cartView = file_get_contents(resource_path('views/cart/index.blade.php'));

        $this->assertIsString($orderView);
        $this->assertStringContainsString('aria-describedby="cancellation-reason-error"', $orderView);
        $this->assertStringContainsString('id="cancellation-reason-error"', $orderView);
        $this->assertStringNotContainsString('aria-disabled="true"', $cartView);
    }

    public function test_auth_forms_keep_csrf_fields_and_never_echo_passwords(): void
    {
        $this->withSession(['_old_input' => ['email' => 'old@example.com', 'password' => 'SecretMarker123']]);

        $this->get('/login')->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('old@example.com')
            ->assertDontSee('SecretMarker123');

        $this->get('/register')->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="phone"', false)
            ->assertSee('name="gender"', false)
            ->assertSee('name="dob"', false)
            ->assertSee('name="address"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertDontSee('name="role"', false)
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="current_tier"', false)
            ->assertDontSee('SecretMarker123');
    }

    public function test_validation_error_and_registration_flash_are_rendered_in_context(): void
    {
        $this->from('/register')->post('/register', [])->assertSessionHasErrors('name');

        $this->get('/register')->assertOk()
            ->assertSee('id="name-error"', false)
            ->assertSee('aria-invalid="true"', false);

        $this->withSession(['status' => 'Đăng ký tài khoản thành công.'])
            ->get('/')
            ->assertOk()
            ->assertSee('role="status"', false)
            ->assertSee('Đăng ký tài khoản thành công.');
    }

    public function test_each_dashboard_escapes_name_and_shows_role_label(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = User::factory()->create([
                'name' => '<script>alert("x")</script>',
                'role' => $role->value,
            ]);

            $this->actingAs($user)
                ->get(route($role->dashboardRouteName()))
                ->assertOk()
                ->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', false)
                ->assertDontSee('<script>alert("x")</script>', false)
                ->assertSee($role->label())
                ->assertSee('method="POST" action="'.route('logout').'"', false);
        }
    }
}
