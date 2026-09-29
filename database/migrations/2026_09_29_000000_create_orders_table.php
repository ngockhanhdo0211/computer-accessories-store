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

        if (Schema::hasTable('orders')) {
            throw new RuntimeException('Order migration found a partial migration state: orders already exists. Inspect its rows, structure and constraints manually; no automatic cleanup is performed.');
        }

        try {
            if (DB::getDriverName() === 'sqlite') {
                $this->createSqliteTable();
            } else {
                $this->createMariaDbOrMySqlTable();
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('Order migration stopped while creating orders. Inspect its structure, constraints and rows manually; no automatic cleanup is performed.', 0, $exception);
        }
    }

    public function down(): void
    {
        $this->assertSupportedDatabase();

        foreach (['order_status_histories', 'order_items', 'coupon_usages', 'refunds'] as $dependent) {
            if (Schema::hasTable($dependent)) {
                throw new RuntimeException("Drop {$dependent} before rolling back orders.");
            }
        }

        Schema::dropIfExists('orders');
    }

    private function createMariaDbOrMySqlTable(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->unique('orders_payment_attempt_unique')->constrained('payment_attempts')->restrictOnUpdate()->restrictOnDelete();
            $table->char('request_key', 36)->nullable();
            $table->string('order_code', 40)->unique('orders_order_code_unique');
            $table->string('status', 32)->default('da_dat');
            $table->string('payment_method', 8);
            $table->string('payment_status', 20);
            $table->string('recipient_name');
            $table->string('recipient_email');
            $table->string('recipient_phone', 40);
            $table->text('recipient_address');
            $table->string('recipient_region', 100);
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->restrictOnUpdate()->restrictOnDelete();
            $table->json('coupon_snapshot_json')->nullable();
            $table->unsignedBigInteger('items_subtotal_vnd');
            $table->unsignedBigInteger('item_discount_vnd')->default(0);
            $table->unsignedBigInteger('shipping_fee_vnd');
            $table->unsignedBigInteger('shipping_discount_vnd')->default(0);
            $table->unsignedBigInteger('total_vnd');
            $table->dateTime('delivered_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['user_id', 'request_key'], 'orders_user_request_unique');
            $table->index(['user_id', 'created_at', 'id'], 'orders_user_created_index');
            $table->index(['status', 'created_at', 'id'], 'orders_status_created_index');
            $table->index(['payment_status', 'created_at', 'id'], 'orders_payment_status_created_index');
            $table->index(['payment_method', 'created_at', 'id'], 'orders_payment_method_created_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_status_check CHECK (BINARY status IN ('da_dat','cho_chuyen_phat','dang_trung_chuyen','da_giao','da_huy')),
                ADD CONSTRAINT orders_payment_method_check CHECK (BINARY payment_method IN ('cod','vnpay')),
                ADD CONSTRAINT orders_payment_status_check CHECK (BINARY payment_status IN ('chua_thanh_toan','da_thanh_toan','that_bai','hoan_tien')),
                ADD CONSTRAINT orders_request_key_check CHECK (request_key IS NULL OR CHAR_LENGTH(request_key) = 36),
                ADD CONSTRAINT orders_payment_source_check CHECK (
                    (BINARY payment_method = 'cod' AND payment_attempt_id IS NULL AND request_key IS NOT NULL)
                    OR (BINARY payment_method = 'vnpay' AND payment_attempt_id IS NOT NULL AND request_key IS NULL)
                ),
                ADD CONSTRAINT orders_coupon_snapshot_check CHECK (
                    (coupon_id IS NULL AND coupon_snapshot_json IS NULL)
                    OR (coupon_id IS NOT NULL AND coupon_snapshot_json IS NOT NULL)
                ),
                ADD CONSTRAINT orders_items_subtotal_check CHECK (items_subtotal_vnd >= 0),
                ADD CONSTRAINT orders_item_discount_check CHECK (item_discount_vnd >= 0 AND item_discount_vnd <= items_subtotal_vnd),
                ADD CONSTRAINT orders_shipping_fee_check CHECK (shipping_fee_vnd >= 0),
                ADD CONSTRAINT orders_shipping_discount_check CHECK (shipping_discount_vnd >= 0 AND shipping_discount_vnd <= shipping_fee_vnd),
                ADD CONSTRAINT orders_total_check CHECK (
                    total_vnd >= 0
                    AND total_vnd = items_subtotal_vnd - item_discount_vnd + shipping_fee_vnd - shipping_discount_vnd
                )
            SQL);
    }

    private function createSqliteTable(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                user_id INTEGER NOT NULL,
                payment_attempt_id INTEGER NULL,
                request_key CHAR(36) NULL,
                order_code VARCHAR(40) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'da_dat',
                payment_method VARCHAR(8) NOT NULL,
                payment_status VARCHAR(20) NOT NULL,
                recipient_name VARCHAR(255) NOT NULL,
                recipient_email VARCHAR(255) NOT NULL,
                recipient_phone VARCHAR(40) NOT NULL,
                recipient_address TEXT NOT NULL,
                recipient_region VARCHAR(100) NOT NULL,
                coupon_id INTEGER NULL,
                coupon_snapshot_json JSON NULL,
                items_subtotal_vnd INTEGER NOT NULL,
                item_discount_vnd INTEGER NOT NULL DEFAULT 0,
                shipping_fee_vnd INTEGER NOT NULL,
                shipping_discount_vnd INTEGER NOT NULL DEFAULT 0,
                total_vnd INTEGER NOT NULL,
                delivered_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT orders_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT orders_payment_attempt_id_foreign FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT orders_coupon_id_foreign FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT orders_order_code_unique UNIQUE (order_code),
                CONSTRAINT orders_payment_attempt_unique UNIQUE (payment_attempt_id),
                CONSTRAINT orders_user_request_unique UNIQUE (user_id, request_key),
                CONSTRAINT orders_status_check CHECK (status IN ('da_dat','cho_chuyen_phat','dang_trung_chuyen','da_giao','da_huy')),
                CONSTRAINT orders_payment_method_check CHECK (payment_method IN ('cod','vnpay')),
                CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('chua_thanh_toan','da_thanh_toan','that_bai','hoan_tien')),
                CONSTRAINT orders_request_key_check CHECK (request_key IS NULL OR LENGTH(request_key) = 36),
                CONSTRAINT orders_payment_source_check CHECK (
                    (payment_method = 'cod' AND payment_attempt_id IS NULL AND request_key IS NOT NULL)
                    OR (payment_method = 'vnpay' AND payment_attempt_id IS NOT NULL AND request_key IS NULL)
                ),
                CONSTRAINT orders_coupon_snapshot_check CHECK (
                    (coupon_id IS NULL AND coupon_snapshot_json IS NULL)
                    OR (coupon_id IS NOT NULL AND coupon_snapshot_json IS NOT NULL)
                ),
                CONSTRAINT orders_coupon_json_check CHECK (coupon_snapshot_json IS NULL OR JSON_VALID(coupon_snapshot_json)),
                CONSTRAINT orders_items_subtotal_check CHECK (items_subtotal_vnd >= 0),
                CONSTRAINT orders_item_discount_check CHECK (item_discount_vnd >= 0 AND item_discount_vnd <= items_subtotal_vnd),
                CONSTRAINT orders_shipping_fee_check CHECK (shipping_fee_vnd >= 0),
                CONSTRAINT orders_shipping_discount_check CHECK (shipping_discount_vnd >= 0 AND shipping_discount_vnd <= shipping_fee_vnd),
                CONSTRAINT orders_total_check CHECK (
                    total_vnd >= 0
                    AND total_vnd = items_subtotal_vnd - item_discount_vnd + shipping_fee_vnd - shipping_discount_vnd
                )
            )
            SQL);

        DB::statement('CREATE INDEX orders_user_created_index ON orders (user_id, created_at, id)');
        DB::statement('CREATE INDEX orders_status_created_index ON orders (status, created_at, id)');
        DB::statement('CREATE INDEX orders_payment_status_created_index ON orders (payment_status, created_at, id)');
        DB::statement('CREATE INDEX orders_payment_method_created_index ON orders (payment_method, created_at, id)');
        DB::statement('CREATE INDEX orders_coupon_id_index ON orders (coupon_id)');
    }

    private function assertDependenciesExist(): void
    {
        foreach (['users', 'payment_attempts', 'coupons'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Order migration requires the {$table} table.");
            }
        }
    }

    private function assertSupportedDatabase(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Order migration supports only SQLite, MariaDB and MySQL.');
        }

        $rawVersion = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        $version = preg_replace('/^5\.5\.5-/', '', $rawVersion);

        if (! preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
            throw new RuntimeException('Cannot determine MariaDB/MySQL version for Order CHECK enforcement.');
        }

        $mariaDb = str_contains(strtolower($version), 'mariadb');
        $minimum = $mariaDb ? '10.2.1' : '8.0.16';

        if (version_compare($matches[1], $minimum, '<')) {
            throw new RuntimeException('Order CHECK constraints require '.($mariaDb ? 'MariaDB' : 'MySQL')." {$minimum} or newer.");
        }
    }
};
