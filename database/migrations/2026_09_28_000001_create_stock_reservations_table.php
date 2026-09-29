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
        $this->assertDependenciesExist();

        if (Schema::hasTable('stock_reservations')) {
            throw new RuntimeException('Stock Reservation migration found a partial migration state: stock_reservations already exists. Inspect its rows, structure and constraints manually; no automatic cleanup is performed.');
        }

        try {
            if (DB::getDriverName() === 'sqlite') {
                $this->createSqliteTable();
            } else {
                $this->createMariaDbOrMySqlTable();
            }
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Stock Reservation migration stopped while creating stock_reservations. Inspect its structure, constraints and rows manually; no automatic cleanup is performed.',
                0,
                $exception,
            );
        }
    }

    public function down(): void
    {
        $this->assertSupportedDatabase();
        Schema::dropIfExists('stock_reservations');
    }

    private function createMariaDbOrMySqlTable(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_attempt_id')->constrained('payment_attempts')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->dateTime('expires_at', 6);
            $table->dateTime('released_at', 6)->nullable();
            $table->dateTime('consumed_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['payment_attempt_id', 'product_id'], 'stock_reservations_attempt_product_unique');
            $table->index(['product_id', 'released_at', 'consumed_at', 'expires_at'], 'stock_reservations_product_active_index');
            $table->index(['expires_at', 'released_at', 'consumed_at'], 'stock_reservations_expiration_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE stock_reservations
                ADD CONSTRAINT stock_reservations_quantity_check CHECK (quantity > 0),
                ADD CONSTRAINT stock_reservations_terminal_check CHECK (released_at IS NULL OR consumed_at IS NULL)
            SQL);
    }

    private function createSqliteTable(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE stock_reservations (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                payment_attempt_id INTEGER NOT NULL,
                product_id INTEGER NOT NULL,
                quantity INTEGER NOT NULL,
                expires_at DATETIME NOT NULL,
                released_at DATETIME NULL,
                consumed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT stock_reservations_attempt_id_foreign FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT stock_reservations_product_id_foreign FOREIGN KEY (product_id) REFERENCES products(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT stock_reservations_attempt_product_unique UNIQUE (payment_attempt_id, product_id),
                CONSTRAINT stock_reservations_quantity_check CHECK (quantity > 0),
                CONSTRAINT stock_reservations_terminal_check CHECK (released_at IS NULL OR consumed_at IS NULL)
            )
            SQL);

        DB::statement('CREATE INDEX stock_reservations_product_active_index ON stock_reservations (product_id, released_at, consumed_at, expires_at)');
        DB::statement('CREATE INDEX stock_reservations_expiration_index ON stock_reservations (expires_at, released_at, consumed_at)');
    }

    private function assertDependenciesExist(): void
    {
        foreach (['payment_attempts', 'products'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Stock Reservation migration requires the {$table} table.");
            }
        }
    }

    private function assertSupportedDatabase(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Stock Reservation migration supports only SQLite, MariaDB and MySQL.');
        }

        $rawVersion = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        $version = preg_replace('/^5\.5\.5-/', '', $rawVersion);

        if (! preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
            throw new RuntimeException('Cannot determine MariaDB/MySQL version for Stock Reservation CHECK enforcement.');
        }

        $mariaDb = str_contains(strtolower($version), 'mariadb');
        $minimum = $mariaDb ? '10.2.1' : '8.0.16';

        if (version_compare($matches[1], $minimum, '<')) {
            throw new RuntimeException('Stock Reservation CHECK constraints require '.($mariaDb ? 'MariaDB' : 'MySQL')." {$minimum} or newer.");
        }
    }
};
