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
            $this->assertContains($target, ['#main-content', route('home'), route('login'), route('register')]);
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
            ->assertSee('method="POST" action="'.route('logout').'"', false)
            ->assertDontSee('href="'.route('logout').'"', false)
            ->assertDontSee('href="'.route('register').'"', false);
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
