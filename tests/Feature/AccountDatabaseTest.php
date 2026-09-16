<?php

namespace Tests\Feature;

use App\Enums\MembershipLevel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_tests_use_isolated_sqlite_memory_database(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_user_column_defaults_and_nullable_phone_unique_constraint(): void
    {
        foreach (['first@example.com', 'second@example.com'] as $email) {
            DB::table('users')->insert([
                'name' => 'Customer',
                'email' => $email,
                'phone' => null,
                'password' => 'hashed-for-schema-test',
            ]);
        }

        $user = DB::table('users')->where('email', 'first@example.com')->first();

        $this->assertSame(UserRole::Customer->value, $user->role);
        $this->assertSame(UserStatus::Active->value, $user->status);
        $this->assertSame(MembershipLevel::Dong->value, $user->current_tier);
        $this->assertSame(0, (int) $user->membership_spending);
        $this->assertSame(0, (int) $user->must_change_password);
        $this->assertSame(2, DB::table('users')->whereNull('phone')->count());

        DB::table('users')->where('email', 'first@example.com')->update(['phone' => '0912345678']);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('users')->where('email', 'second@example.com')->update(['phone' => '0912345678']);
    }

    public function test_user_extension_migration_down_and_up_on_sqlite(): void
    {
        $checks = require database_path('migrations/2026_09_16_000001_add_user_domain_checks_to_users_table.php');
        $migration = require database_path('migrations/2026_09_16_000000_add_account_fields_to_users_table.php');

        $checks->down();
        $migration->down();

        foreach (['phone', 'gender', 'dob', 'address', 'role', 'status', 'current_tier', 'membership_spending', 'last_login_at', 'must_change_password'] as $column) {
            $this->assertFalse(Schema::hasColumn('users', $column), $column);
        }

        $migration->up();
        $checks->up();

        foreach (['phone', 'gender', 'dob', 'address', 'role', 'status', 'current_tier', 'membership_spending', 'last_login_at', 'must_change_password'] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), $column);
        }
    }
}
