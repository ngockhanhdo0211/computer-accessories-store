<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SQLITE_GUARD_COLUMN = 'users_domain_check_guard';

    public function up(): void
    {
        $driver = $this->supportedDriver();
        $checks = $this->checks($driver);

        foreach ($checks as $name => $expression) {
            if (DB::table('users')->whereRaw("NOT ($expression)")->exists()) {
                throw new RuntimeException("Cannot add $name: users contains invalid existing data. Correct the data before rerunning this migration.");
            }
        }

        if ($driver === 'mysql') {
            $clauses = [];

            foreach ($checks as $name => $expression) {
                $clauses[] = 'ADD CONSTRAINT '.chr(96).$name.chr(96)." CHECK ($expression)";
            }

            DB::statement('ALTER TABLE '.chr(96).'users'.chr(96).' '.implode(', ', $clauses));

            $created = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'users')
                ->where('CONSTRAINT_TYPE', 'CHECK')
                ->whereIn('CONSTRAINT_NAME', array_keys($checks))
                ->pluck('CONSTRAINT_NAME')
                ->all();

            if (count($created) !== count($checks)) {
                throw new RuntimeException('Users CHECK DDL completed but metadata verification failed. Inspect the table before retrying; MariaDB/MySQL ALTER TABLE cannot be rolled back by the migration transaction.');
            }

            return;
        }

        $clauses = [];

        foreach ($checks as $name => $expression) {
            $clauses[] = "CONSTRAINT \"$name\" CHECK ($expression)";
        }

        DB::statement(
            'ALTER TABLE "users" ADD COLUMN "'.self::SQLITE_GUARD_COLUMN.'" '
            .'INTEGER GENERATED ALWAYS AS (1) VIRTUAL '.implode(' ', $clauses)
        );
    }

    public function down(): void
    {
        $driver = $this->supportedDriver();

        if ($driver === 'mysql') {
            $clauses = [];

            foreach (array_keys($this->checks($driver)) as $name) {
                $clauses[] = 'DROP CONSTRAINT '.chr(96).$name.chr(96);
            }

            DB::statement('ALTER TABLE '.chr(96).'users'.chr(96).' '.implode(', ', $clauses));

            return;
        }

        DB::statement('ALTER TABLE "users" DROP COLUMN "'.self::SQLITE_GUARD_COLUMN.'"');
    }

    private function supportedDriver(): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $version = DB::selectOne('SELECT sqlite_version() AS version')->version;

            if (version_compare($version, '3.35.0', '<')) {
                throw new RuntimeException('SQLite 3.35.0 or newer is required to drop the users CHECK guard column.');
            }

            return $driver;
        }

        if ($driver !== 'mysql') {
            throw new RuntimeException("Unsupported database driver for users CHECK constraints: $driver.");
        }

        $version = DB::selectOne('SELECT VERSION() AS version')->version;

        $isMariaDb = stripos($version, 'mariadb') !== false;
        $versionPattern = $isMariaDb
            ? '/(\d+\.\d+\.\d+)(?=-MariaDB)/i'
            : '/^(\d+\.\d+\.\d+)/';

        if (! preg_match($versionPattern, $version, $matches)) {
            throw new RuntimeException("Cannot determine database CHECK support from version: $version.");
        }

        if ($isMariaDb) {
            if (version_compare($matches[1], '10.2.1', '<')) {
                throw new RuntimeException('MariaDB 10.2.1 or newer is required for enforced CHECK constraints.');
            }

            $enabled = DB::selectOne('SELECT @@check_constraint_checks AS enabled')->enabled;

            if ((int) $enabled !== 1) {
                throw new RuntimeException('MariaDB check_constraint_checks must be enabled for this migration.');
            }
        } elseif (version_compare($matches[1], '8.0.16', '<')) {
            throw new RuntimeException('MySQL 8.0.16 or newer is required for enforced CHECK constraints.');
        }

        return $driver;
    }

    /** @return array<string, string> */
    private function checks(string $driver): array
    {
        $quote = $driver === 'mysql' ? chr(96) : '"';
        $binary = $driver === 'mysql' ? 'BINARY ' : '';

        $role = $quote.'role'.$quote;
        $status = $quote.'status'.$quote;
        $tier = $quote.'current_tier'.$quote;
        $gender = $quote.'gender'.$quote;
        $spending = $quote.'membership_spending'.$quote;
        $changePassword = $quote.'must_change_password'.$quote;

        return [
            'users_role_check' => "$binary$role IN ('customer', 'employee', 'admin')",
            'users_status_check' => "$binary$status IN ('active', 'locked', 'inactive')",
            'users_current_tier_check' => "$binary$tier IN ('dong', 'bac', 'vang', 'kim_cuong')",
            'users_gender_check' => "$gender IS NULL OR $binary$gender IN ('nam', 'nu')",
            'users_membership_spending_check' => "$spending >= 0",
            'users_must_change_password_check' => "$changePassword IN (0, 1)",
        ];
    }
};
