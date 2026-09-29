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

        if (Schema::hasTable('payment_attempts')) {
            throw new RuntimeException('Payment Attempt migration found a partial migration state: payment_attempts already exists. Inspect its rows, structure and constraints manually; no automatic cleanup is performed.');
        }

        try {
            if (DB::getDriverName() === 'sqlite') {
                $this->createSqliteTable();
            } else {
                $this->createMariaDbOrMySqlTable();
            }
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Payment Attempt migration stopped while creating payment_attempts. Inspect its structure, constraints and rows manually; no automatic cleanup is performed.',
                0,
                $exception,
            );
        }
    }

    public function down(): void
    {
        $this->assertSupportedDatabase();

        if (Schema::hasTable('stock_reservations')) {
            throw new RuntimeException('Drop stock_reservations before rolling back payment_attempts.');
        }

        Schema::dropIfExists('payment_attempts');
    }

    private function createMariaDbOrMySqlTable(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('shipping_rate_id')->constrained('shipping_rates')->restrictOnUpdate()->restrictOnDelete();
            $table->char('request_key', 36);
            $table->string('gateway_reference', 100);
            $table->string('gateway_transaction_id', 100)->nullable();
            $table->string('status', 20)->default('chua_thanh_toan');
            $table->unsignedBigInteger('amount_vnd');
            $table->json('items_snapshot_json');
            $table->json('recipient_snapshot_json');
            $table->json('pricing_snapshot_json');
            $table->unsignedBigInteger('shipping_fee_vnd');
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->restrictOnUpdate()->restrictOnDelete();
            $table->dateTime('expires_at', 6);
            $table->dateTime('verified_at', 6)->nullable();
            $table->string('gateway_result_code', 40)->nullable();
            $table->boolean('late_callback_exception')->default(false);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['user_id', 'request_key'], 'payment_attempts_user_request_unique');
            $table->unique('gateway_reference', 'payment_attempts_gateway_reference_unique');
            $table->unique('gateway_transaction_id', 'payment_attempts_gateway_transaction_unique');
            $table->index(['user_id', 'created_at'], 'payment_attempts_user_created_index');
            $table->index(['status', 'expires_at'], 'payment_attempts_status_expires_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_attempts
                ADD CONSTRAINT payment_attempts_status_check CHECK (BINARY status IN ('chua_thanh_toan','da_thanh_toan','that_bai','hoan_tien')),
                ADD CONSTRAINT payment_attempts_amount_vnd_check CHECK (amount_vnd >= 0),
                ADD CONSTRAINT payment_attempts_shipping_fee_vnd_check CHECK (shipping_fee_vnd >= 0),
                ADD CONSTRAINT payment_attempts_request_key_check CHECK (CHAR_LENGTH(request_key) = 36),
                ADD CONSTRAINT payment_attempts_late_callback_check CHECK (late_callback_exception IN (0,1))
            SQL);
    }

    private function createSqliteTable(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE payment_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                user_id INTEGER NOT NULL,
                shipping_rate_id INTEGER NOT NULL,
                request_key CHAR(36) NOT NULL,
                gateway_reference VARCHAR(100) NOT NULL,
                gateway_transaction_id VARCHAR(100) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'chua_thanh_toan',
                amount_vnd INTEGER NOT NULL,
                items_snapshot_json JSON NOT NULL,
                recipient_snapshot_json JSON NOT NULL,
                pricing_snapshot_json JSON NOT NULL,
                shipping_fee_vnd INTEGER NOT NULL,
                coupon_id INTEGER NULL,
                expires_at DATETIME NOT NULL,
                verified_at DATETIME NULL,
                gateway_result_code VARCHAR(40) NULL,
                late_callback_exception INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT payment_attempts_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT payment_attempts_shipping_rate_id_foreign FOREIGN KEY (shipping_rate_id) REFERENCES shipping_rates(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT payment_attempts_coupon_id_foreign FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT payment_attempts_user_request_unique UNIQUE (user_id, request_key),
                CONSTRAINT payment_attempts_gateway_reference_unique UNIQUE (gateway_reference),
                CONSTRAINT payment_attempts_gateway_transaction_unique UNIQUE (gateway_transaction_id),
                CONSTRAINT payment_attempts_status_check CHECK (status IN ('chua_thanh_toan','da_thanh_toan','that_bai','hoan_tien')),
                CONSTRAINT payment_attempts_amount_vnd_check CHECK (amount_vnd >= 0),
                CONSTRAINT payment_attempts_shipping_fee_vnd_check CHECK (shipping_fee_vnd >= 0),
                CONSTRAINT payment_attempts_request_key_check CHECK (LENGTH(request_key) = 36),
                CONSTRAINT payment_attempts_late_callback_check CHECK (late_callback_exception IN (0,1)),
                CONSTRAINT payment_attempts_items_json_check CHECK (JSON_VALID(items_snapshot_json)),
                CONSTRAINT payment_attempts_recipient_json_check CHECK (JSON_VALID(recipient_snapshot_json)),
                CONSTRAINT payment_attempts_pricing_json_check CHECK (JSON_VALID(pricing_snapshot_json))
            )
            SQL);

        DB::statement('CREATE INDEX payment_attempts_user_created_index ON payment_attempts (user_id, created_at)');
        DB::statement('CREATE INDEX payment_attempts_status_expires_index ON payment_attempts (status, expires_at)');
        DB::statement('CREATE INDEX payment_attempts_shipping_rate_id_index ON payment_attempts (shipping_rate_id)');
        DB::statement('CREATE INDEX payment_attempts_coupon_id_index ON payment_attempts (coupon_id)');
    }

    private function assertDependenciesExist(): void
    {
        foreach (['users', 'shipping_rates', 'coupons'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Payment Attempt migration requires the {$table} table.");
            }
        }
    }

    private function assertSupportedDatabase(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Payment Attempt migration supports only SQLite, MariaDB and MySQL.');
        }

        $rawVersion = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        $version = preg_replace('/^5\.5\.5-/', '', $rawVersion);

        if (! preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
            throw new RuntimeException('Cannot determine MariaDB/MySQL version for Payment Attempt CHECK enforcement.');
        }

        $mariaDb = str_contains(strtolower($version), 'mariadb');
        $minimum = $mariaDb ? '10.2.1' : '8.0.16';

        if (version_compare($matches[1], $minimum, '<')) {
            throw new RuntimeException('Payment Attempt CHECK constraints require '.($mariaDb ? 'MariaDB' : 'MySQL')." {$minimum} or newer.");
        }
    }
};
