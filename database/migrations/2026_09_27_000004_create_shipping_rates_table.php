<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertSupportedDatabase();

        if (Schema::hasTable('shipping_rates')) {
            throw new RuntimeException('Shipping Rate migration is pending but shipping_rates already exists. Inspect its rows, structure and constraints manually; no automatic cleanup is performed.');
        }

        try {
            if (DB::getDriverName() === 'sqlite') {
                $this->createSqliteTable();
            } else {
                $this->createMariaDbOrMySqlTable();
            }

            $this->createRegionGuards();

            $now = now();
            DB::table('shipping_rates')->insert([
                ['region_key' => 'ha_noi', 'fee_vnd' => 30_000, 'created_at' => $now, 'updated_at' => $now],
                ['region_key' => 'other', 'fee_vnd' => 45_000, 'created_at' => $now, 'updated_at' => $now],
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Shipping Rate migration stopped while creating or initializing shipping_rates. Inspect its structure, constraints, triggers and rows manually; no automatic cleanup is performed.',
                0,
                $exception
            );
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Shipping Rate migration supports only SQLite, MariaDB and MySQL.');
        }

        Schema::dropIfExists('shipping_rates');
    }

    private function createSqliteTable(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE shipping_rates (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                region_key VARCHAR(16) NOT NULL,
                fee_vnd INTEGER NOT NULL,
                updated_by INTEGER NULL,
                created_at DATETIME,
                updated_at DATETIME,
                CONSTRAINT shipping_rates_region_key_check CHECK (region_key IN ('ha_noi', 'other')),
                CONSTRAINT shipping_rates_fee_vnd_check CHECK (fee_vnd >= 0),
                CONSTRAINT shipping_rates_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL
            )
        SQL);

        Schema::table('shipping_rates', function (Blueprint $table) {
            $table->unique('region_key');
            $table->index('updated_by');
        });
    }

    private function createMariaDbOrMySqlTable(): void
    {
        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->string('region_key', 16)->unique();
            $table->bigInteger('fee_vnd');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnUpdate()->nullOnDelete();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });

        DB::statement("ALTER TABLE shipping_rates ADD CONSTRAINT shipping_rates_region_key_check CHECK (region_key IN ('ha_noi', 'other'))");
        DB::statement('ALTER TABLE shipping_rates ADD CONSTRAINT shipping_rates_fee_vnd_check CHECK (fee_vnd >= 0)');
    }

    private function createRegionGuards(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER shipping_rates_region_key_guard
                BEFORE UPDATE OF region_key ON shipping_rates
                WHEN NEW.region_key IS NOT OLD.region_key
                BEGIN SELECT RAISE(ABORT, 'shipping rate region keys are immutable'); END");
            DB::statement("CREATE TRIGGER shipping_rates_delete_guard
                BEFORE DELETE ON shipping_rates
                BEGIN SELECT RAISE(ABORT, 'shipping rate regions cannot be deleted'); END");

            return;
        }

        DB::unprepared("CREATE TRIGGER shipping_rates_region_key_guard BEFORE UPDATE ON shipping_rates
            FOR EACH ROW BEGIN
                IF NOT (NEW.region_key <=> OLD.region_key) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'shipping rate region keys are immutable';
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER shipping_rates_delete_guard BEFORE DELETE ON shipping_rates
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'shipping rate regions cannot be deleted'");
    }

    private function assertSupportedDatabase(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Shipping Rate migration supports only SQLite, MariaDB and MySQL.');
        }

        $rawVersion = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        $version = preg_replace('/^5\.5\.5-/', '', $rawVersion);

        if (! preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
            throw new RuntimeException('Cannot determine MariaDB/MySQL version for Shipping Rate CHECK enforcement.');
        }

        $mariaDb = str_contains(strtolower($version), 'mariadb');
        $minimum = $mariaDb ? '10.2.1' : '8.0.16';

        if (version_compare($matches[1], $minimum, '<')) {
            throw new RuntimeException('Shipping Rate CHECK constraints require '.($mariaDb ? 'MariaDB' : 'MySQL')." {$minimum} or newer.");
        }
    }
};
