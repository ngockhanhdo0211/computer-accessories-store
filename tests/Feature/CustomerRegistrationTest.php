<?php

namespace Tests\Feature;

use App\Enums\MembershipLevel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function validRegistration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nguyễn Văn An',
            'email' => 'an@example.com',
            'phone' => '0912345678',
            'gender' => 'nam',
            'dob' => '2000-01-01',
            'address' => 'Hà Nội',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ], $overrides);
    }

    public function test_guest_can_view_registration_form_with_csrf_token(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('Đăng ký khách hàng')
            ->assertSee('name="_token"', false);
    }

    public function test_authenticated_user_cannot_view_or_submit_registration(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/register')->assertRedirect('/dashboard');
        $this->post('/register', $this->validRegistration())->assertRedirect('/dashboard');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_creates_only_a_customer_and_keeps_guest_logged_out(): void
    {
        $response = $this->post('/register', $this->validRegistration([
            'role' => 'admin',
            'status' => 'locked',
            'current_tier' => 'kim_cuong',
            'membership_level' => 'kim_cuong',
            'membership_spending' => 999999999,
            'must_change_password' => true,
        ]));

        $response->assertRedirect('/')
            ->assertSessionHas('status', 'Đăng ký tài khoản thành công.');
        $this->assertGuest();

        $user = User::query()->sole();

        $this->assertTrue($user->isCustomer());
        $this->assertTrue($user->isActive());
        $this->assertSame(MembershipLevel::Dong, $user->current_tier);
        $this->assertSame(0, $user->membership_spending);
        $this->assertFalse($user->must_change_password);
        $this->assertNotSame('SecurePass123', $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('SecurePass123', $user->password));
    }

    public function test_email_and_phone_are_normalized_before_uniqueness_checks(): void
    {
        $this->post('/register', $this->validRegistration([
            'email' => '  AN@EXAMPLE.COM  ',
            'phone' => '+84 912 345 678',
            'name' => '  Nguyễn   Văn  An  ',
            'address' => '  Hà   Nội  ',
        ]))->assertRedirect('/');

        $this->assertDatabaseHas('users', [
            'email' => 'an@example.com',
            'phone' => '0912345678',
            'name' => 'Nguyễn Văn An',
            'address' => 'Hà Nội',
        ]);

        $this->post('/register', $this->validRegistration([
            'email' => 'an@example.com',
            'phone' => '0987654321',
        ]))->assertSessionHasErrors('email');
    }

    public function test_duplicate_phone_is_rejected_after_normalization(): void
    {
        User::factory()->create(['phone' => '0912345678']);

        $this->post('/register', $this->validRegistration([
            'email' => 'another@example.com',
            'phone' => '0912 345 678',
        ]))->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_phone_rejects_boolean_array_scientific_and_malformed_values(): void
    {
        foreach ([true, ['0912345678'], '9.12345678e8', '09123A5678', '0212345678', '091234567'] as $phone) {
            $this->post('/register', $this->validRegistration([
                'phone' => $phone,
            ]))->assertSessionHasErrors('phone');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_gender_future_birth_date_and_mismatched_password_are_rejected(): void
    {
        $this->post('/register', $this->validRegistration([
            'gender' => 'khac',
            'dob' => now()->addDay()->toDateString(),
            'password_confirmation' => 'DifferentPass123',
        ]))->assertSessionHasErrors(['gender', 'dob', 'password', 'password_confirmation']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_required_and_whitespace_only_fields_are_rejected_without_flashing_passwords(): void
    {
        $this->post('/register', [
            'name' => '   ',
            'email' => '   ',
            'phone' => '   ',
            'gender' => '   ',
            'dob' => '',
            'address' => '   ',
            'password' => '        ',
            'password_confirmation' => '        ',
        ])->assertSessionHasErrors([
            'name', 'email', 'phone', 'gender', 'dob', 'address', 'password',
        ])->assertSessionMissing('_old_input.password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_factory_states_and_enum_casts_are_consistent(): void
    {
        $customer = User::factory()->create();
        $employee = User::factory()->employee()->locked()->create();
        $admin = User::factory()->admin()->inactive()->create();

        $this->assertSame(UserRole::Customer, $customer->role);
        $this->assertSame(UserStatus::Active, $customer->status);
        $this->assertSame(MembershipLevel::Dong, $customer->current_tier);
        $this->assertSame(UserRole::Employee, $employee->role);
        $this->assertSame(UserStatus::Locked, $employee->status);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertSame(UserStatus::Inactive, $admin->status);
        $this->assertFalse($employee->isActive());
        $this->assertTrue($admin->isAdmin());
    }

    public function test_database_email_conflict_after_validation_becomes_a_validation_error(): void
    {
        $this->assertPostValidationUniqueConflict('email');
    }

    public function test_database_phone_conflict_after_validation_becomes_a_validation_error(): void
    {
        $this->assertPostValidationUniqueConflict('phone');
    }

    private function assertPostValidationUniqueConflict(string $field): void
    {
        $registration = $this->validRegistration();
        $inserted = false;

        User::saving(function () use (&$inserted, $field, $registration): void {
            if ($inserted) {
                return;
            }

            $inserted = true;

            DB::table('users')->insert([
                'name' => 'Concurrent customer',
                'email' => $field === 'email' ? $registration['email'] : 'concurrent@example.com',
                'phone' => $field === 'phone' ? $registration['phone'] : null,
                'password' => Hash::make('OtherPass123'),
            ]);
        });

        try {
            $this->post('/register', $registration)->assertSessionHasErrors($field);
        } finally {
            User::flushEventListeners();
        }

        $this->assertTrue($inserted);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_prehashed_password_is_not_hashed_twice(): void
    {
        $hash = Hash::make('SecurePass123');
        $user = User::factory()->create(['password' => $hash]);

        $this->assertSame($hash, $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('SecurePass123', $user->password));
    }

    public function test_enum_casts_write_string_values_and_read_enum_instances(): void
    {
        $user = User::factory()->create();
        $user->role = UserRole::Employee;
        $user->status = UserStatus::Locked;
        $user->current_tier = MembershipLevel::Bac;
        $user->save();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'employee',
            'status' => 'locked',
            'current_tier' => 'bac',
        ]);

        $user->refresh();

        $this->assertSame(UserRole::Employee, $user->role);
        $this->assertSame(UserStatus::Locked, $user->status);
        $this->assertSame(MembershipLevel::Bac, $user->current_tier);
    }

    public function test_validation_preserves_ordinary_input_without_passwords(): void
    {
        $this->post('/register', $this->validRegistration([
            'name' => '  Nguyễn Văn An  ',
            'email' => '  AN@EXAMPLE.COM  ',
            'gender' => 'invalid',
        ]))->assertSessionHasErrors('gender')
            ->assertSessionHas('_old_input.name', 'Nguyễn Văn An')
            ->assertSessionHas('_old_input.email', 'AN@EXAMPLE.COM')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');

        $this->get('/register')
            ->assertSee('value="Nguyễn Văn An"', false)
            ->assertSee('value="AN@EXAMPLE.COM"', false);
    }

    public function test_sensitive_fields_are_not_mass_assignable(): void
    {
        $user = new User;
        $user->fill([
            'name' => 'Khách',
            'email' => 'khach@example.com',
            'role' => 'admin',
            'status' => 'locked',
            'current_tier' => 'kim_cuong',
            'membership_spending' => 100000000,
            'must_change_password' => true,
        ]);

        $this->assertNull($user->role);
        $this->assertNull($user->status);
        $this->assertNull($user->current_tier);
        $this->assertNull($user->membership_spending);
        $this->assertNull($user->must_change_password);
    }
}
