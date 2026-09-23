<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationAndAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'TestOnlyPassword123';

    private function user(UserRole $role = UserRole::Customer, UserStatus $status = UserStatus::Active): User
    {
        return User::factory()->create([
            'role' => $role->value,
            'status' => $status->value,
            'password' => self::PASSWORD,
            'last_login_at' => null,
        ]);
    }

    private function credentials(User $user, array $overrides = []): array
    {
        return array_merge([
            'email' => $user->email,
            'password' => self::PASSWORD,
        ], $overrides);
    }

    public function test_guest_sees_login_form_with_csrf_accessible_fields_and_registration_link(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('for="email"', false)
            ->assertSee('id="email"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('for="password"', false)
            ->assertSee('id="password"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee(route('register'));
    }

    public function test_authenticated_user_cannot_open_or_submit_login(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->get('/login')->assertRedirect('/dashboard');
        $this->post('/login', $this->credentials($user))->assertRedirect('/dashboard');
    }

    public function test_all_three_active_roles_log_in_and_reach_only_their_dashboard(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = $this->user($role);

            $this->post('/login', $this->credentials($user))->assertRedirect('/dashboard');
            $this->assertAuthenticatedAs($user);
            $this->get('/dashboard')->assertRedirect(route($role->dashboardRouteName()));
            $this->get(route($role->dashboardRouteName()))
                ->assertOk()
                ->assertSee($user->name)
                ->assertSee($role->label())
                ->assertSee($role === UserRole::Admin ? 'Tổng quan quản trị' : 'dashboard nền tảng')
                ->assertSee('action="'.route('logout').'"', false);

            $this->post('/logout')->assertRedirect('/');
            $this->assertGuest();
        }
    }

    public function test_email_is_trimmed_and_lowercased_before_authentication(): void
    {
        $user = $this->user();

        $this->post('/login', $this->credentials($user, [
            'email' => '  '.strtoupper($user->email).'  ',
        ]))->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_and_non_active_accounts_have_same_safe_error(): void
    {
        $active = $this->user();
        $locked = $this->user(UserRole::Customer, UserStatus::Locked);
        $inactive = $this->user(UserRole::Customer, UserStatus::Inactive);

        foreach ([
            $this->credentials($active, ['password' => 'WrongPassword123']),
            ['email' => 'missing@example.com', 'password' => self::PASSWORD],
            $this->credentials($locked),
            $this->credentials($inactive),
        ] as $credentials) {
            $this->post('/login', $credentials)
                ->assertSessionHasErrors(['email' => 'Thông tin đăng nhập không hợp lệ.']);
            $this->assertGuest();
        }

        foreach ([$active, $locked, $inactive] as $user) {
            $this->assertNull($user->fresh()->last_login_at);
        }
    }

    public function test_login_rejects_whitespace_and_preserves_only_email_old_input(): void
    {
        $this->post('/login', [
            'email' => '   ',
            'password' => '   ',
        ])->assertSessionHasErrors(['email', 'password'])
            ->assertSessionMissing('_old_input.password');

        $this->post('/login', [
            'email' => '  PERSON@EXAMPLE.COM  ',
            'password' => 'WrongPassword123',
        ])->assertSessionHas('_old_input.email', 'PERSON@EXAMPLE.COM')
            ->assertSessionMissing('_old_input.password');

        $this->get('/login')
            ->assertSee('value="PERSON@EXAMPLE.COM"', false)
            ->assertDontSee('value="WrongPassword123"', false);
    }

    public function test_success_regenerates_session_and_updates_last_login_without_changing_password(): void
    {
        $user = $this->user();
        $passwordHash = $user->getRawOriginal('password');

        $this->get('/login');
        $oldSessionId = session()->getId();

        $this->post('/login', $this->credentials($user))->assertRedirect('/dashboard');

        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertSame($passwordHash, $user->fresh()->getRawOriginal('password'));
    }

    public function test_allowed_intended_dashboard_is_used_after_login(): void
    {
        $user = $this->user();

        $this->get('/customer/dashboard')->assertRedirect(route('login'));
        $this->post('/login', $this->credentials($user))
            ->assertRedirect(route('customer.dashboard'));
    }

    public function test_unauthorized_or_external_intended_url_is_discarded(): void
    {
        foreach ([route('admin.dashboard'), 'https://example.invalid/redirect'] as $intended) {
            $user = $this->user();
            $this->withSession(['url.intended' => $intended])
                ->post('/login', $this->credentials($user))
                ->assertRedirect(route('dashboard'));

            $this->get('/dashboard')->assertRedirect(route('customer.dashboard'));
            $this->post('/logout');
        }
    }

    public function test_customer_can_only_use_customer_dashboard(): void
    {
        $this->actingAs($this->user());

        $this->get('/customer/dashboard')->assertOk();
        $this->get('/employee/dashboard')->assertForbidden();
        $this->get('/admin/dashboard')->assertForbidden();
    }

    public function test_employee_can_only_use_employee_dashboard(): void
    {
        $this->actingAs($this->user(UserRole::Employee));

        $this->get('/employee/dashboard')->assertOk();
        $this->get('/customer/dashboard')->assertForbidden();
        $this->get('/admin/dashboard')->assertForbidden();
    }

    public function test_admin_can_only_use_admin_dashboard(): void
    {
        $this->actingAs($this->user(UserRole::Admin));

        $this->get('/admin/dashboard')->assertOk();
        $this->get('/customer/dashboard')->assertForbidden();
        $this->get('/employee/dashboard')->assertForbidden();
    }

    public function test_guest_is_redirected_from_all_protected_dashboards(): void
    {
        foreach (['/dashboard', '/customer/dashboard', '/employee/dashboard', '/admin/dashboard'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    public function test_logout_is_post_only_invalidates_session_and_regenerates_csrf_token(): void
    {
        $user = $this->user();
        $this->post('/login', $this->credentials($user));
        $oldSessionId = session()->getId();
        $oldToken = session()->token();

        $this->get('/logout')->assertStatus(405);
        $this->get('/customer/dashboard')->assertOk()
            ->assertSee('name="_token"', false);

        $this->post('/logout')
            ->assertRedirect('/')
            ->assertSessionHas('status', 'Đã đăng xuất.');

        $this->assertGuest();
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->post('/logout')->assertRedirect(route('login'));
    }

    public function test_locked_existing_session_is_revoked_on_next_protected_request(): void
    {
        $this->assertRevokedAfterStatusChange(UserStatus::Locked);
    }

    public function test_inactive_existing_session_is_revoked_on_next_protected_request(): void
    {
        $this->assertRevokedAfterStatusChange(UserStatus::Inactive);
    }

    private function assertRevokedAfterStatusChange(UserStatus $status): void
    {
        $user = $this->user();
        $this->post('/login', $this->credentials($user))->assertRedirect('/dashboard');
        $oldSessionId = session()->getId();

        DB::table('users')->where('id', $user->id)->update(['status' => $status->value]);

        $this->get('/customer/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_five_failed_attempts_limit_the_same_email_and_ip_without_locking_account(): void
    {
        $user = $this->user();
        $key = 'login:'.hash('sha256', $user->email.'|127.0.0.1');
        RateLimiter::clear($key);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', $this->credentials($user, ['password' => 'WrongPassword123']))
                ->assertSessionHasErrors('email');
        }

        $this->assertSame(5, RateLimiter::attempts($key));
        $this->post('/login', $this->credentials($user))
            ->assertSessionHasErrors(['email' => 'Quá nhiều lần đăng nhập thất bại. Vui lòng thử lại sau.']);

        $this->assertGuest();
        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }

    public function test_successful_login_clears_failed_attempt_counter(): void
    {
        $user = $this->user();
        $key = 'login:'.hash('sha256', $user->email.'|127.0.0.1');
        RateLimiter::clear($key);

        $this->post('/login', $this->credentials($user, ['password' => 'WrongPassword123']))
            ->assertSessionHasErrors('email');
        $this->assertSame(1, RateLimiter::attempts($key));

        $this->post('/login', $this->credentials($user))->assertRedirect('/dashboard');
        $this->assertSame(0, RateLimiter::attempts($key));
    }

    public function test_rate_limit_key_uses_normalized_email_and_ip(): void
    {
        $user = $this->user();
        $originalKey = 'login:'.hash('sha256', $user->email.'|127.0.0.1');
        $otherIpKey = 'login:'.hash('sha256', $user->email.'|198.51.100.7');
        RateLimiter::clear($originalKey);
        RateLimiter::clear($otherIpKey);

        $this->post('/login', $this->credentials($user, [
            'email' => '  '.strtoupper($user->email).'  ',
            'password' => 'WrongPassword123',
        ]))->assertSessionHasErrors('email');

        $this->assertSame(1, RateLimiter::attempts($originalKey));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post('/login', $this->credentials($user, ['password' => 'WrongPassword123']))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, RateLimiter::attempts($originalKey));
        $this->assertSame(1, RateLimiter::attempts($otherIpKey));
    }

    public function test_invalid_stored_status_rejects_login_and_revokes_existing_session(): void
    {
        $user = $this->user();
        $this->post('/login', $this->credentials($user))->assertRedirect('/dashboard');

        DB::statement('PRAGMA ignore_check_constraints = ON');

        try {
            DB::table('users')->where('id', $user->id)->update(['status' => 'unknown']);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }

        $this->get('/customer/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post('/login', $this->credentials($user))
            ->assertSessionHasErrors(['email' => 'Thông tin đăng nhập không hợp lệ.']);
        $this->assertGuest();
    }

    public function test_invalid_stored_role_is_denied_without_defaulting_to_customer(): void
    {
        $user = $this->user();

        DB::statement('PRAGMA ignore_check_constraints = ON');

        try {
            DB::table('users')->where('id', $user->id)->update(['role' => 'unknown']);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }

        $this->actingAs($user);

        $this->get('/dashboard')->assertForbidden();
        $this->get('/customer/dashboard')->assertForbidden();

        auth()->logout();
        $this->post('/login', $this->credentials($user))
            ->assertSessionHasErrors(['email' => 'Thông tin đăng nhập không hợp lệ.']);
        $this->assertGuest();
    }

    public function test_login_request_cannot_change_role_status_tier_or_redirect_destination(): void
    {
        $user = $this->user();

        $this->post('/login', $this->credentials($user, [
            'role' => 'admin',
            'status' => 'inactive',
            'current_tier' => 'kim_cuong',
            'redirect' => 'https://example.invalid/',
        ]))->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame('dong', $user->current_tier->value);
    }
}
