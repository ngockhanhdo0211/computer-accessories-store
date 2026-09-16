<?php

namespace Tests\Feature;

use App\Enums\MembershipLevel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class UserDomainChecksTest extends TestCase
{
    use RefreshDatabase;

    private function insertUser(array $overrides = []): void
    {
        DB::table('users')->insert(array_merge([
            'name' => 'Database check probe',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('TestOnlyPassword'),
        ], $overrides));
    }

    private function assertDatabaseRejects(string $column, mixed $value, string $constraint): void
    {
        try {
            $this->insertUser([$column => $value]);
            $this->fail("Database accepted a value rejected by $constraint.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($constraint, $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_database_rejects_unknown_role(): void
    {
        $this->assertDatabaseRejects('role', 'manager', 'users_role_check');
    }

    public function test_database_rejects_unknown_status(): void
    {
        $this->assertDatabaseRejects('status', 'suspended', 'users_status_check');
    }

    public function test_database_rejects_unknown_current_tier(): void
    {
        $this->assertDatabaseRejects('current_tier', 'platinum', 'users_current_tier_check');
    }

    public function test_database_rejects_unknown_gender(): void
    {
        $this->assertDatabaseRejects('gender', 'other', 'users_gender_check');
    }

    public function test_database_rejects_negative_membership_spending(): void
    {
        $this->assertDatabaseRejects('membership_spending', -1, 'users_membership_spending_check');
    }

    public function test_database_rejects_non_boolean_must_change_password(): void
    {
        $this->assertDatabaseRejects('must_change_password', 2, 'users_must_change_password_check');
    }

    public function test_database_accepts_all_enum_values_and_boundary_values(): void
    {
        $roles = UserRole::cases();
        $statuses = UserStatus::cases();
        $tiers = MembershipLevel::cases();

        foreach ($tiers as $index => $tier) {
            $this->insertUser([
                'role' => $roles[$index % count($roles)]->value,
                'status' => $statuses[$index % count($statuses)]->value,
                'current_tier' => $tier->value,
                'gender' => [null, 'nam', 'nu', null][$index],
                'membership_spending' => $index === 0 ? 0 : 100,
                'must_change_password' => $index % 2,
            ]);
        }

        $this->assertDatabaseCount('users', 4);
        $this->assertSame(2, DB::table('users')->whereNull('gender')->count());
        $this->assertSame(1, DB::table('users')->where('membership_spending', 0)->count());
        $this->assertSame(2, DB::table('users')->where('must_change_password', 0)->count());
        $this->assertSame(2, DB::table('users')->where('must_change_password', 1)->count());
    }

    public function test_factory_default_passes_database_checks(): void
    {
        $user = User::factory()->create();

        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame(MembershipLevel::Dong, $user->current_tier);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_sqlite_schema_contains_six_named_checks(): void
    {
        $sql = DB::table('sqlite_schema')->where('type', 'table')->where('name', 'users')->value('sql');

        foreach ([
            'users_role_check',
            'users_status_check',
            'users_current_tier_check',
            'users_gender_check',
            'users_membership_spending_check',
            'users_must_change_password_check',
        ] as $name) {
            $this->assertStringContainsString($name, $sql);
        }

        $this->assertStringContainsString('GENERATED ALWAYS AS (1) VIRTUAL', $sql);
    }

    public function test_invalid_existing_data_stops_migration_without_changing_it(): void
    {
        $checks = require database_path('migrations/2026_09_16_000001_add_user_domain_checks_to_users_table.php');
        $checks->down();
        $this->insertUser(['role' => 'manager']);

        try {
            $checks->up();
            $this->fail('Migration accepted invalid existing data.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('users_role_check', $exception->getMessage());
            $this->assertStringContainsString('invalid existing data', $exception->getMessage());
        }

        $this->assertSame('manager', DB::table('users')->value('role'));

        DB::table('users')->update(['role' => 'customer']);
        $checks->up();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_check_migration_down_and_up_preserve_user_structure_data_and_uniques(): void
    {
        $user = User::factory()->create([
            'name' => 'Nguyễn Văn An',
            'phone' => '0912345678',
            'gender' => 'nam',
            'dob' => '2000-01-01',
            'address' => 'Hà Nội',
            'membership_spending' => 100000,
            'last_login_at' => '2026-01-01 00:00:00',
            'must_change_password' => true,
        ]);
        User::factory()->create(['phone' => null, 'gender' => null]);
        User::factory()->create(['phone' => null]);

        $indexesBefore = $this->userIndexNames();
        $columnsBefore = $this->userColumnDefinitions();
        $rowsBefore = $this->userRows();

        foreach (['users_email_unique', 'users_phone_unique', 'users_role_index', 'users_status_index', 'users_current_tier_index'] as $name) {
            $this->assertContains($name, $indexesBefore);
        }

        Schema::create('user_check_dependents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
        });

        DB::table('user_check_dependents')->insert(['user_id' => $user->id]);

        $checks = require database_path('migrations/2026_09_16_000001_add_user_domain_checks_to_users_table.php');
        $checks->down();

        foreach ($this->checkNames() as $name) {
            $this->assertStringNotContainsString($name, $this->usersSql());
        }

        $this->assertSame($columnsBefore, $this->userColumnDefinitions());
        $this->assertSame($indexesBefore, $this->userIndexNames());
        $this->assertSame($rowsBefore, $this->userRows());
        $this->assertSame(2, DB::table('users')->whereNull('phone')->count());
        $this->assertUniqueConstraintsSurvive($user->email, '0912345678');
        $this->assertDatabaseHas('user_check_dependents', ['user_id' => $user->id]);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

        DB::table('users')->where('id', $user->id)->update(['role' => 'manager']);
        $this->assertSame('manager', DB::table('users')->where('id', $user->id)->value('role'));
        DB::table('users')->where('id', $user->id)->update(['role' => 'customer']);

        $checks->up();

        foreach ($this->checkNames() as $name) {
            $this->assertStringContainsString($name, $this->usersSql());
        }

        $this->assertSame($columnsBefore, $this->userColumnDefinitions());
        $this->assertSame($indexesBefore, $this->userIndexNames());
        $this->assertSame($rowsBefore, $this->userRows());
        $this->assertSame(2, DB::table('users')->whereNull('phone')->count());
        $this->assertUniqueConstraintsSurvive($user->email, '0912345678');
        $this->assertDatabaseHas('user_check_dependents', ['user_id' => $user->id]);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

        try {
            DB::table('users')->where('id', $user->id)->update(['role' => 'manager']);
            $this->fail('Role CHECK was not restored.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('users_role_check', $exception->getMessage());
        }

        $this->assertSame($rowsBefore, $this->userRows());
    }

    public function test_check_migration_also_runs_on_file_backed_sqlite(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'users-check-');
        $this->assertNotFalse($file);

        $originalConnection = DB::getDefaultConnection();
        $fileConnection = 'users_check_file';

        config()->set("database.connections.$fileConnection", array_replace(
            config('database.connections.sqlite'),
            ['database' => $file]
        ));

        DB::setDefaultConnection($fileConnection);

        try {
            (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
            (require database_path('migrations/2026_09_16_000000_add_account_fields_to_users_table.php'))->up();

            $this->insertUser();
            $checks = require database_path('migrations/2026_09_16_000001_add_user_domain_checks_to_users_table.php');
            $checks->up();

            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseRejectsOnPopulatedTable('role', 'manager', 'users_role_check');

            $checks->down();
            $this->assertDatabaseCount('users', 1);
            $checks->up();

            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseRejectsOnPopulatedTable('status', 'suspended', 'users_status_check');
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::purge($fileConnection);
            config()->set("database.connections.$fileConnection", null);

            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function assertUniqueConstraintsSurvive(string $email, string $phone): void
    {
        try {
            $this->insertUser(['email' => $email]);
            $this->fail('Duplicate email was accepted.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertStringContainsString('users.email', $exception->getMessage());
        }

        try {
            $this->insertUser(['phone' => $phone]);
            $this->fail('Duplicate phone was accepted.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertStringContainsString('users.phone', $exception->getMessage());
        }
    }

    private function assertDatabaseRejectsOnPopulatedTable(string $column, mixed $value, string $constraint): void
    {
        try {
            $this->insertUser([$column => $value]);
            $this->fail("Database accepted a value rejected by $constraint.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($constraint, $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 1);
    }

    /** @return list<string> */
    private function checkNames(): array
    {
        return [
            'users_role_check',
            'users_status_check',
            'users_current_tier_check',
            'users_gender_check',
            'users_membership_spending_check',
            'users_must_change_password_check',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function userColumnDefinitions(): array
    {
        return array_values(array_map(
            fn (object $column): array => (array) $column,
            array_filter(
                DB::select('PRAGMA table_xinfo("users")'),
                fn (object $column): bool => $column->name !== 'users_domain_check_guard'
            )
        ));
    }

    /** @return list<array<string, mixed>> */
    private function userRows(): array
    {
        $columns = [
            'id', 'name', 'email', 'email_verified_at', 'password', 'remember_token',
            'created_at', 'updated_at', 'phone', 'gender', 'dob', 'address',
            'role', 'status', 'current_tier', 'membership_spending',
            'last_login_at', 'must_change_password',
        ];

        return DB::table('users')->orderBy('id')->get($columns)
            ->map(fn (object $user): array => (array) $user)
            ->all();
    }

    private function usersSql(): string
    {
        return DB::table('sqlite_schema')->where('type', 'table')->where('name', 'users')->value('sql');
    }

    /** @return list<string> */
    private function userIndexNames(): array
    {
        $names = array_map(fn (object $index): string => $index->name, DB::select('PRAGMA index_list("users")'));
        sort($names);

        return $names;
    }
}
