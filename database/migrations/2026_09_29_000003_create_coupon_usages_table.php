<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertSupportedDatabase();
        $this->assertDependenciesExist();
        if (Schema::hasTable('coupon_usages')) {
            throw new RuntimeException('Coupon Usage migration found a partial migration state: coupon_usages already exists. Inspect it manually.');
        }
        try {
            DB::getDriverName() === 'sqlite' ? $this->createSqliteTable() : $this->createMariaDbOrMySqlTable();
            $this->createLifecycleTriggers();
        } catch (Throwable $exception) {
            throw new RuntimeException('Coupon Usage migration stopped. Inspect its structure, constraints, triggers and rows manually.', 0, $exception);
        }
    }

    public function down(): void
    {
        $this->assertSupportedDatabase();
        if (Schema::hasTable('coupon_usages') && DB::table('coupon_usages')->exists()) {
            throw new RuntimeException('Coupon Usage rollback requires an empty coupon_usages table. No rows were deleted.');
        }
        $this->dropLifecycleTriggers();
        Schema::dropIfExists('coupon_usages');
    }

    private function createMariaDbOrMySqlTable(): void
    {
        Schema::create('coupon_usages', function ($table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->unique('coupon_usages_attempt_unique')->constrained('payment_attempts')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->unique('coupon_usages_order_unique')->constrained('orders')->restrictOnUpdate()->restrictOnDelete();
            $table->string('status', 16);
            $table->dateTime('reserved_at', 6)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('consumed_at', 6)->nullable();
            $table->dateTime('released_at', 6)->nullable();
            $table->string('release_reason', 100)->nullable();
            $table->boolean('late_callback_exception')->default(false);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['coupon_id', 'status', 'expires_at'], 'coupon_usages_capacity_index');
            $table->index(['coupon_id', 'customer_id', 'status', 'expires_at'], 'coupon_usages_customer_capacity_index');
            $table->index(['status', 'expires_at'], 'coupon_usages_expiration_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE coupon_usages
                ADD CONSTRAINT coupon_usages_status_check CHECK (BINARY status IN ('reserved','consumed','released')),
                ADD CONSTRAINT coupon_usages_late_callback_check CHECK (late_callback_exception IN (0,1)),
                ADD CONSTRAINT coupon_usages_state_check CHECK (
                    (BINARY status = 'reserved' AND payment_attempt_id IS NOT NULL AND order_id IS NULL AND consumed_at IS NULL AND released_at IS NULL AND expires_at IS NOT NULL AND late_callback_exception = 0)
                    OR (BINARY status = 'released' AND order_id IS NULL AND consumed_at IS NULL AND released_at IS NOT NULL AND expires_at IS NOT NULL AND late_callback_exception = 0)
                    OR (BINARY status = 'consumed' AND order_id IS NOT NULL AND consumed_at IS NOT NULL AND released_at IS NULL AND late_callback_exception = 0)
                    OR (BINARY status = 'consumed' AND payment_attempt_id IS NOT NULL AND order_id IS NOT NULL AND consumed_at IS NOT NULL AND released_at IS NOT NULL AND late_callback_exception = 1)
                ),
                ADD CONSTRAINT coupon_usages_time_check CHECK (
                    (reserved_at IS NULL OR expires_at IS NULL OR expires_at >= reserved_at)
                    AND (reserved_at IS NULL OR consumed_at IS NULL OR consumed_at >= reserved_at)
                    AND (reserved_at IS NULL OR released_at IS NULL OR released_at >= reserved_at)
                )
            SQL);
    }

    private function createSqliteTable(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE coupon_usages (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                coupon_id INTEGER NOT NULL, customer_id INTEGER NOT NULL,
                payment_attempt_id INTEGER NULL, order_id INTEGER NULL,
                status VARCHAR(16) NOT NULL, reserved_at DATETIME NULL, expires_at DATETIME NULL,
                consumed_at DATETIME NULL, released_at DATETIME NULL,
                release_reason VARCHAR(100) NULL, late_callback_exception INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                CONSTRAINT coupon_usages_coupon_id_foreign FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT coupon_usages_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES users(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT coupon_usages_payment_attempt_id_foreign FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT coupon_usages_order_id_foreign FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT coupon_usages_attempt_unique UNIQUE (payment_attempt_id),
                CONSTRAINT coupon_usages_order_unique UNIQUE (order_id),
                CONSTRAINT coupon_usages_status_check CHECK (status IN ('reserved','consumed','released')),
                CONSTRAINT coupon_usages_late_callback_check CHECK (late_callback_exception IN (0,1)),
                CONSTRAINT coupon_usages_state_check CHECK (
                    (status = 'reserved' AND payment_attempt_id IS NOT NULL AND order_id IS NULL AND consumed_at IS NULL AND released_at IS NULL AND expires_at IS NOT NULL AND late_callback_exception = 0)
                    OR (status = 'released' AND order_id IS NULL AND consumed_at IS NULL AND released_at IS NOT NULL AND expires_at IS NOT NULL AND late_callback_exception = 0)
                    OR (status = 'consumed' AND order_id IS NOT NULL AND consumed_at IS NOT NULL AND released_at IS NULL AND late_callback_exception = 0)
                    OR (status = 'consumed' AND payment_attempt_id IS NOT NULL AND order_id IS NOT NULL AND consumed_at IS NOT NULL AND released_at IS NOT NULL AND late_callback_exception = 1)
                ),
                CONSTRAINT coupon_usages_time_check CHECK (
                    (reserved_at IS NULL OR expires_at IS NULL OR expires_at >= reserved_at)
                    AND (reserved_at IS NULL OR consumed_at IS NULL OR consumed_at >= reserved_at)
                    AND (reserved_at IS NULL OR released_at IS NULL OR released_at >= reserved_at)
                )
            )
            SQL);
        DB::statement('CREATE INDEX coupon_usages_capacity_index ON coupon_usages (coupon_id, status, expires_at)');
        DB::statement('CREATE INDEX coupon_usages_customer_capacity_index ON coupon_usages (coupon_id, customer_id, status, expires_at)');
        DB::statement('CREATE INDEX coupon_usages_expiration_index ON coupon_usages (status, expires_at)');
    }

    private function createLifecycleTriggers(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER coupon_usages_lifecycle_update BEFORE UPDATE ON coupon_usages
                FOR EACH ROW
                WHEN NEW.coupon_id <> OLD.coupon_id
                  OR NEW.customer_id <> OLD.customer_id
                  OR COALESCE(NEW.payment_attempt_id, -1) <> COALESCE(OLD.payment_attempt_id, -1)
                  OR COALESCE(NEW.reserved_at, '') <> COALESCE(OLD.reserved_at, '')
                  OR COALESCE(NEW.expires_at, '') <> COALESCE(OLD.expires_at, '')
                  OR NEW.created_at <> OLD.created_at
                  OR NOT (
                    (OLD.status = 'reserved' AND NEW.status = 'released')
                    OR (OLD.status = 'reserved' AND NEW.status = 'consumed' AND NEW.late_callback_exception = 0)
                    OR (OLD.status = 'released' AND NEW.status = 'consumed' AND NEW.late_callback_exception = 1 AND NEW.released_at = OLD.released_at)
                  )
                BEGIN SELECT RAISE(ABORT, 'coupon_usages invalid lifecycle transition'); END
                SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER coupon_usages_no_delete BEFORE DELETE ON coupon_usages
                BEGIN SELECT RAISE(ABORT, 'coupon_usages cannot be deleted'); END
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER coupon_usages_lifecycle_update BEFORE UPDATE ON coupon_usages
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.coupon_id <=> OLD.coupon_id)
                   OR NOT (NEW.customer_id <=> OLD.customer_id)
                   OR NOT (NEW.payment_attempt_id <=> OLD.payment_attempt_id)
                   OR NOT (NEW.reserved_at <=> OLD.reserved_at)
                   OR NOT (NEW.expires_at <=> OLD.expires_at)
                   OR NOT (NEW.created_at <=> OLD.created_at)
                   OR NOT (
                       (BINARY OLD.status = 'reserved' AND BINARY NEW.status = 'released')
                       OR (BINARY OLD.status = 'reserved' AND BINARY NEW.status = 'consumed' AND NEW.late_callback_exception = 0)
                       OR (BINARY OLD.status = 'released' AND BINARY NEW.status = 'consumed' AND NEW.late_callback_exception = 1 AND NEW.released_at <=> OLD.released_at)
                   ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'coupon_usages invalid lifecycle transition';
                END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER coupon_usages_no_delete BEFORE DELETE ON coupon_usages
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'coupon_usages cannot be deleted'
            SQL);
    }

    private function dropLifecycleTriggers(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS coupon_usages_lifecycle_update');
        DB::statement('DROP TRIGGER IF EXISTS coupon_usages_no_delete');
    }

    private function assertDependenciesExist(): void
    {
        foreach (['coupons', 'users', 'payment_attempts', 'orders'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Coupon Usage migration requires the '.$table.' table.');
            }
        }
    }

    private function assertSupportedDatabase(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Unsupported database.');
        }
        $rawVersion = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        $version = preg_replace('/^5\.5\.5-/', '', $rawVersion);
        if (! preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
            throw new RuntimeException('Cannot determine database version.');
        }
        $mariaDb = str_contains(strtolower($version), 'mariadb');
        $minimum = $mariaDb ? '10.2.1' : '8.0.16';
        if (version_compare($matches[1], $minimum, '<')) {
            throw new RuntimeException('Database version cannot enforce Coupon Usage CHECK constraints.');
        }
    }
};
