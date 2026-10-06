<?php

namespace Tests\Feature;

use App\Actions\PromoteCustomerToAdmin;
use App\Enums\MembershipLevel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SecureProductionAdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_promotes_one_normalized_existing_customer_and_writes_system_audit(): void
    {
        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'email_verified_at' => now()->subDay(),
            'membership_spending' => 125000,
            'current_tier' => MembershipLevel::Dong,
            'password' => 'OriginalPassword123',
        ]);
        $passwordHash = $user->getRawOriginal('password');
        $verifiedAt = $user->email_verified_at?->toJSON();

        $this->artisan('app:promote-customer-to-admin', ['email' => '  CUSTOMER@EXAMPLE.COM  '])
            ->expectsOutput('Tài khoản Admin đã sẵn sàng.')
            ->assertSuccessful();

        $user->refresh();
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame($passwordHash, $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('OriginalPassword123', $user->password));
        $this->assertSame($verifiedAt, $user->email_verified_at?->toJSON());
        $this->assertSame(MembershipLevel::Dong, $user->current_tier);
        $this->assertSame(125000, $user->membership_spending);

        $audit = AuditLog::query()->sole();
        $this->assertNull($audit->actor_id);
        $this->assertSame(PromoteCustomerToAdmin::AUDIT_ACTION, $audit->action);
        $this->assertSame(User::class, $audit->subject_type);
        $this->assertSame($user->id, $audit->subject_id);
        $this->assertSame(['role' => 'customer', 'status' => 'active'], $audit->before_json);
        $this->assertSame(['role' => 'admin', 'status' => 'active'], $audit->after_json);
        $this->assertNotNull($audit->request_id);
    }

    public function test_promoted_admin_receives_admin_authorization_without_other_role_access(): void
    {
        $password = 'AuthorizationPassword123';
        $user = User::factory()->create([
            'email' => 'authorization@example.com',
            'password' => $password,
        ]);

        $this->artisan('app:promote-customer-to-admin', ['email' => $user->email])->assertSuccessful();

        $this->post('/login', ['email' => $user->email, 'password' => $password])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertRedirect(route('admin.dashboard'));
        $this->get('/admin/dashboard')->assertOk();
        $this->get('/customer/dashboard')->assertForbidden();
        $this->get('/employee/dashboard')->assertForbidden();
    }

    public function test_same_command_is_idempotent_only_with_valid_audit_evidence(): void
    {
        $user = User::factory()->create(['email' => 'replay@example.com']);

        $this->artisan('app:promote-customer-to-admin', ['email' => $user->email])->assertSuccessful();
        $firstAudit = AuditLog::query()->sole();

        $this->artisan('app:promote-customer-to-admin', ['email' => strtoupper($user->email)])
            ->assertSuccessful();

        $this->assertSame(UserRole::Admin, $user->fresh()->role);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame($firstAudit->request_id, AuditLog::query()->sole()->request_id);
    }

    public function test_existing_admin_without_valid_evidence_fails_closed(): void
    {
        $adminWithoutEvidence = User::factory()->admin()->create(['email' => 'missing-audit@example.com']);

        $this->artisan('app:promote-customer-to-admin', ['email' => $adminWithoutEvidence->email])
            ->expectsOutput('Tài khoản Admin không có audit evidence hợp lệ.')
            ->assertFailed();

        $adminWithWrongEvidence = User::factory()->admin()->create(['email' => 'wrong-audit@example.com']);
        (new AuditLog)->forceFill([
            'actor_id' => null,
            'action' => PromoteCustomerToAdmin::AUDIT_ACTION,
            'subject_type' => User::class,
            'subject_id' => $adminWithWrongEvidence->id,
            'before_json' => ['role' => 'customer', 'status' => 'active'],
            'after_json' => ['role' => 'employee', 'status' => 'active'],
            'request_id' => (string) Str::uuid(),
            'created_at' => now(),
        ])->save();

        $this->artisan('app:promote-customer-to-admin', ['email' => $adminWithWrongEvidence->email])
            ->expectsOutput('Tài khoản Admin không có audit evidence hợp lệ.')
            ->assertFailed();
    }

    public function test_existing_admin_with_duplicate_valid_evidence_fails_closed(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'duplicate-audit@example.com']);

        foreach (range(1, 2) as $unused) {
            (new AuditLog)->forceFill([
                'actor_id' => null,
                'action' => PromoteCustomerToAdmin::AUDIT_ACTION,
                'subject_type' => User::class,
                'subject_id' => $admin->id,
                'before_json' => ['role' => 'customer', 'status' => 'active'],
                'after_json' => ['role' => 'admin', 'status' => 'active'],
                'request_id' => (string) Str::uuid(),
                'created_at' => now(),
            ])->save();
        }

        $this->artisan('app:promote-customer-to-admin', ['email' => $admin->email])
            ->expectsOutput('Tài khoản Admin không có audit evidence hợp lệ.')
            ->assertFailed();
    }

    public function test_missing_inactive_locked_and_non_customer_accounts_are_rejected(): void
    {
        $this->artisan('app:promote-customer-to-admin', ['email' => 'missing@example.com'])
            ->expectsOutput('Không tìm thấy tài khoản Customer phù hợp.')
            ->assertFailed();

        foreach ([UserStatus::Inactive, UserStatus::Locked] as $status) {
            $user = User::factory()->create(['status' => $status, 'email' => $status->value.'@example.com']);

            $this->artisan('app:promote-customer-to-admin', ['email' => $user->email])
                ->expectsOutput('Tài khoản phải đang ở trạng thái active.')
                ->assertFailed();
        }

        $employee = User::factory()->employee()->create(['email' => 'employee@example.com']);
        $this->artisan('app:promote-customer-to-admin', ['email' => $employee->email])
            ->expectsOutput('Chỉ tài khoản Customer mới có thể được nâng thành Admin.')
            ->assertFailed();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_production_requires_explicit_confirmation_before_reading_the_account(): void
    {
        config()->set('app.env', 'production');
        $user = User::factory()->create(['email' => 'production@example.com']);

        $this->artisan('app:promote-customer-to-admin', ['email' => $user->email])
            ->expectsConfirmation(
                'APP_ENV=production. Bạn có chắc muốn nâng Customer đã chỉ định thành Admin?',
                'no',
            )
            ->expectsOutput('Đã hủy; không có tài khoản nào được thay đổi.')
            ->assertFailed();

        $this->assertSame(UserRole::Customer, $user->fresh()->role);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_non_interactive_production_execution_fails_closed(): void
    {
        config()->set('app.env', 'production');
        $user = User::factory()->create(['email' => 'non-interactive@example.com']);

        $this->artisan('app:promote-customer-to-admin', [
            'email' => $user->email,
            '--no-interaction' => true,
        ])->expectsOutput('Production yêu cầu xác nhận tương tác; không có tài khoản nào được thay đổi.')
            ->assertFailed();

        $this->assertSame(UserRole::Customer, $user->fresh()->role);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_role_change(): void
    {
        $user = User::factory()->create(['email' => 'rollback@example.com']);
        Event::listen('eloquent.creating: '.AuditLog::class, fn () => throw new RuntimeException('forced audit failure'));

        $this->artisan('app:promote-customer-to-admin', ['email' => $user->email])
            ->expectsOutput('Không thể nâng tài khoản thành Admin; không có thay đổi nào được xác nhận.')
            ->assertFailed();

        $this->assertSame(UserRole::Customer, $user->fresh()->role);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
