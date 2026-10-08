<?php

namespace Tests\Feature;

use App\Actions\PromoteCustomerToEmployee;
use App\Enums\MembershipLevel;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SecureProductionEmployeeBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_auto_discovered_and_promotes_only_role_and_updated_at(): void
    {
        $user = User::factory()->create([
            'name' => 'Nhân viên dự kiến',
            'email' => 'employee-candidate@example.com',
            'phone' => '0912345678',
            'gender' => 'nu',
            'dob' => '1995-05-06',
            'address' => 'Hà Nội',
            'email_verified_at' => now()->subDays(3),
            'membership_spending' => 125_000,
            'current_tier' => MembershipLevel::Dong,
            'last_login_at' => now()->subDays(2),
            'remember_token' => 'remember-token-value',
            'password' => 'OriginalPassword123',
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subDay(),
        ]);
        $beforeUpdatedAt = $user->updated_at;
        $beforeHash = $this->nonRoleStateHash($user);
        DB::table('sessions')->insert([
            'id' => 'employee-bootstrap-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'bootstrap-regression',
            'payload' => 'unchanged-session-payload',
            'last_activity' => 1_700_000_000,
        ]);
        $sessionHash = hash('sha256', json_encode((array) DB::table('sessions')->where('id', 'employee-bootstrap-session')->firstOrFail(), JSON_THROW_ON_ERROR));

        $this->assertArrayHasKey('app:promote-customer-to-employee', Artisan::all());
        $this->artisan('app:promote-customer-to-employee', ['email' => '  EMPLOYEE-CANDIDATE@EXAMPLE.COM  '])
            ->expectsOutput('Tài khoản Employee đã sẵn sàng.')
            ->assertSuccessful();

        $user->refresh();
        $this->assertSame(UserRole::Employee, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertTrue($user->updated_at->greaterThan($beforeUpdatedAt));
        $this->assertSame($beforeHash, $this->nonRoleStateHash($user));
        $this->assertTrue(Hash::check('OriginalPassword123', $user->password));
        $this->assertSame(
            $sessionHash,
            hash('sha256', json_encode((array) DB::table('sessions')->where('id', 'employee-bootstrap-session')->firstOrFail(), JSON_THROW_ON_ERROR)),
        );

        $audit = AuditLog::query()->sole();
        $this->assertNull($audit->actor_id);
        $this->assertSame(PromoteCustomerToEmployee::AUDIT_ACTION, $audit->action);
        $this->assertSame(User::class, $audit->subject_type);
        $this->assertSame($user->id, $audit->subject_id);
        $this->assertSame(['role' => 'customer', 'status' => 'active'], $audit->before_json);
        $this->assertSame(['role' => 'employee', 'status' => 'active'], $audit->after_json);
        $this->assertTrue(Str::isUuid((string) $audit->request_id));
        $this->assertSame($user->updated_at?->toJSON(), $audit->created_at?->toJSON());
    }

    public function test_production_confirmation_shows_normalized_target_and_employee_warning(): void
    {
        config()->set('app.env', 'production');
        $user = User::factory()->create(['email' => 'production-employee@example.com']);

        $this->artisan('app:promote-customer-to-employee', ['email' => '  PRODUCTION-EMPLOYEE@EXAMPLE.COM '])
            ->expectsOutput('Employee có quyền xử lý đơn hàng, vận chuyển, tồn kho và Support Chat theo ma trận quyền hiện hành.')
            ->expectsOutput('Email mục tiêu: production-employee@example.com')
            ->expectsConfirmation('APP_ENV=production. Bạn có chắc muốn nâng Customer này thành Employee?', 'yes')
            ->assertSuccessful();

        $this->assertSame(UserRole::Employee, $user->fresh()->role);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_production_rejection_and_non_interactive_mode_fail_before_query(): void
    {
        config()->set('app.env', 'production');
        $declined = User::factory()->create(['email' => 'declined@example.com']);

        $this->artisan('app:promote-customer-to-employee', ['email' => $declined->email])
            ->expectsOutput('Email mục tiêu: declined@example.com')
            ->expectsConfirmation('APP_ENV=production. Bạn có chắc muốn nâng Customer này thành Employee?', 'no')
            ->expectsOutput('Đã hủy; không có tài khoản nào được thay đổi.')
            ->assertFailed();
        $this->assertSame(UserRole::Customer, $declined->fresh()->role);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $this->artisan('app:promote-customer-to-employee', [
            'email' => 'non-interactive@example.com',
            '--no-interaction' => true,
        ])->expectsOutput('Production yêu cầu xác nhận tương tác; không có tài khoản nào được thay đổi.')
            ->assertFailed();

        $this->assertSame(0, $queries);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_invalid_missing_inactive_locked_admin_and_employee_accounts_are_rejected(): void
    {
        $this->artisan('app:promote-customer-to-employee', ['email' => 'not-an-email'])
            ->assertFailed();
        $this->artisan('app:promote-customer-to-employee', ['email' => 'missing@example.com'])
            ->expectsOutput('Không tìm thấy tài khoản Customer phù hợp.')
            ->assertFailed();

        foreach ([UserStatus::Inactive, UserStatus::Locked] as $status) {
            $user = User::factory()->create(['status' => $status, 'email' => $status->value.'-employee@example.com']);
            $this->artisan('app:promote-customer-to-employee', ['email' => $user->email])
                ->expectsOutput('Tài khoản phải đang ở trạng thái active.')
                ->assertFailed();
        }

        $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);
        $this->artisan('app:promote-customer-to-employee', ['email' => $admin->email])
            ->expectsOutput('Chỉ tài khoản Customer mới có thể được nâng thành Employee.')
            ->assertFailed();

        $employee = User::factory()->employee()->create(['email' => 'existing-employee@example.com']);
        $this->artisan('app:promote-customer-to-employee', ['email' => $employee->email])
            ->expectsOutput('Tài khoản Employee không có audit evidence hợp lệ.')
            ->assertFailed();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_replay_requires_exactly_one_consistent_audit(): void
    {
        $user = User::factory()->create(['email' => 'employee-replay@example.com']);
        $this->artisan('app:promote-customer-to-employee', ['email' => $user->email])->assertSuccessful();
        $requestId = AuditLog::query()->sole()->request_id;
        $updatedAt = $user->fresh()->updated_at?->toJSON();

        $this->artisan('app:promote-customer-to-employee', ['email' => strtoupper($user->email)])
            ->assertSuccessful();
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame($requestId, AuditLog::query()->sole()->request_id);
        $this->assertSame($updatedAt, $user->fresh()->updated_at?->toJSON());

        $wrong = User::factory()->employee()->create(['email' => 'wrong-evidence@example.com']);
        $this->insertAudit($wrong, ['role' => 'customer', 'status' => 'active'], ['role' => 'admin', 'status' => 'active']);
        $this->artisan('app:promote-customer-to-employee', ['email' => $wrong->email])
            ->expectsOutput('Tài khoản Employee không có audit evidence hợp lệ.')
            ->assertFailed();

        $duplicate = User::factory()->employee()->create(['email' => 'duplicate-evidence@example.com']);
        $this->insertAudit($duplicate);
        $this->insertAudit($duplicate);
        $this->artisan('app:promote-customer-to-employee', ['email' => $duplicate->email])
            ->expectsOutput('Tài khoản Employee không có audit evidence hợp lệ.')
            ->assertFailed();

        $customer = User::factory()->create(['email' => 'customer-with-evidence@example.com']);
        $this->insertAudit($customer);
        $this->artisan('app:promote-customer-to-employee', ['email' => $customer->email])
            ->expectsOutput('Phát hiện audit evidence không nhất quán cho tài khoản Customer.')
            ->assertFailed();
    }

    public function test_audit_failure_rolls_back_role_and_timestamp(): void
    {
        $user = User::factory()->create([
            'email' => 'employee-rollback@example.com',
            'updated_at' => now()->subDay(),
        ]);
        $updatedAt = $user->updated_at?->toJSON();
        Event::listen('eloquent.creating: '.AuditLog::class, fn () => throw new RuntimeException('forced audit failure'));

        $this->artisan('app:promote-customer-to-employee', ['email' => $user->email])
            ->expectsOutput('Không thể nâng tài khoản thành Employee; không có thay đổi nào được xác nhận.')
            ->assertFailed();

        $user->refresh();
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertSame($updatedAt, $user->updated_at?->toJSON());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_promoted_employee_receives_only_employee_authorization_after_login(): void
    {
        $password = 'EmployeeAuthorization123';
        $user = User::factory()->create([
            'email' => 'employee-authorization@example.com',
            'password' => $password,
        ]);
        $order = Order::factory()->create(['status' => OrderStatus::Placed]);
        OrderItem::factory()->for($order)->create();
        $inTransitOrder = Order::factory()->create(['status' => OrderStatus::InTransit]);
        OrderItem::factory()->for($inTransitOrder)->create();

        $this->artisan('app:promote-customer-to-employee', ['email' => $user->email])->assertSuccessful();
        $this->post('/login', ['email' => $user->email, 'password' => $password])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))->assertRedirect(route('employee.dashboard'));
        foreach ([
            route('employee.dashboard'),
            route('employee.orders.index'),
            route('employee.order-cancellation-requests.index'),
            route('employee.orders.return-inspections.show', $order->order_code),
            route('employee.support.index'),
        ] as $allowed) {
            $this->get($allowed)->assertOk();
        }
        $this->get(route('employee.orders.return-inspections.show', $inTransitOrder->order_code))->assertForbidden();

        foreach ([
            route('admin.dashboard'),
            route('admin.products.index'),
            route('admin.categories.index'),
            route('admin.brands.index'),
            route('admin.shipping-rates.index'),
            route('admin.coupons.index'),
            route('admin.refunds.index'),
            route('cart.index'),
            route('checkout.show'),
            route('orders.index'),
        ] as $forbidden) {
            $this->get($forbidden)->assertForbidden();
        }
        $this->post(route('admin.coupons.store'))->assertForbidden();
    }

    private function nonRoleStateHash(User $user): string
    {
        $attributes = $user->fresh()->getAttributes();
        unset($attributes['role'], $attributes['updated_at']);
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, string> $before @param array<string, string> $after */
    private function insertAudit(
        User $user,
        array $before = ['role' => 'customer', 'status' => 'active'],
        array $after = ['role' => 'employee', 'status' => 'active'],
    ): void {
        (new AuditLog)->forceFill([
            'actor_id' => null,
            'action' => PromoteCustomerToEmployee::AUDIT_ACTION,
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'before_json' => $before,
            'after_json' => $after,
            'request_id' => (string) Str::uuid(),
            'created_at' => now(),
        ])->save();
    }
}
