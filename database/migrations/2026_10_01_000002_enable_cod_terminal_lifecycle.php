<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'inventory_transactions_type_order_item_unique';

    private const EVENT_UNIQUE_INDEX = 'order_status_histories_event_unique';

    private const INVENTORY_INSERT_TRIGGER = 'inventory_transactions_terminal_insert_check';

    public function up(): void
    {
        $this->assertSupportedDatabase();
        $this->assertDependencies();

        if ($this->hasInventoryBoundary() || $this->hasEventBoundary()
            || $this->hasInventoryInsertGuard() || $this->couponSchemaAllowsCodRelease()
            || $this->couponTriggerAllowsCodRelease()) {
            throw new RuntimeException('COD terminal lifecycle migration found a partial state. Inspect its index, CHECK and trigger manually.');
        }

        $duplicates = DB::table('inventory_transactions')
            ->whereNotNull('order_item_id')
            ->select(['type', 'order_item_id'])
            ->groupBy(['type', 'order_item_id'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new RuntimeException('COD terminal lifecycle migration found duplicate inventory transaction types for an Order Item. No rows were changed.');
        }

        if (DB::table('order_status_histories')->select('event_key')->groupBy('event_key')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('COD terminal lifecycle migration found duplicate Order event keys. No rows were changed.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteCouponUsages(true);
            DB::statement('CREATE UNIQUE INDEX '.self::UNIQUE_INDEX.' ON inventory_transactions (type, order_item_id)');
            DB::statement('CREATE UNIQUE INDEX '.self::EVENT_UNIQUE_INDEX.' ON order_status_histories (event_key)');
            $this->createSqliteInventoryInsertGuard();

            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS coupon_usages_lifecycle_update');
        DB::statement('ALTER TABLE coupon_usages DROP CONSTRAINT coupon_usages_state_check');
        DB::statement('ALTER TABLE coupon_usages ADD CONSTRAINT coupon_usages_state_check CHECK ('.$this->couponStateCheck().')');
        $this->createMariaDbCouponTrigger(true);
        DB::statement('ALTER TABLE inventory_transactions ADD CONSTRAINT '.self::UNIQUE_INDEX.' UNIQUE (type, order_item_id)');
        DB::statement('ALTER TABLE order_status_histories ADD CONSTRAINT '.self::EVENT_UNIQUE_INDEX.' UNIQUE (event_key)');
        $this->createMariaDbInventoryInsertGuard();
    }

    public function down(): void
    {
        $this->assertSupportedDatabase();
        $this->assertDependencies();

        if (! $this->hasInventoryBoundary() || ! $this->hasEventBoundary()
            || ! $this->hasInventoryInsertGuard() || ! $this->couponSchemaAllowsCodRelease()
            || ! $this->couponTriggerAllowsCodRelease()) {
            throw new RuntimeException('COD terminal lifecycle rollback found a partial state. Inspect its index, CHECK and trigger manually.');
        }
        if (DB::table('coupon_usages')
            ->where('status', 'released')
            ->whereNull('payment_attempt_id')
            ->whereNotNull('order_id')
            ->exists()) {
            throw new RuntimeException('COD terminal lifecycle rollback cannot remove a Coupon lifecycle used by existing COD cancellation evidence.');
        }
        if (DB::table('inventory_transactions')->where('type', 'cancel_restore')->exists()) {
            throw new RuntimeException('COD terminal lifecycle rollback cannot remove the exactly-once boundary while cancel_restore evidence exists.');
        }
        if (DB::table('orders')->whereIn('status', ['da_giao', 'da_huy'])->exists()
            || DB::table('order_status_histories')->whereIn('to_status', ['da_giao', 'da_huy'])->exists()) {
            throw new RuntimeException('COD terminal lifecycle rollback cannot remove terminal event protection while terminal Order evidence exists.');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER '.self::INVENTORY_INSERT_TRIGGER);
            DB::statement('DROP INDEX '.self::EVENT_UNIQUE_INDEX);
            DB::statement('DROP INDEX '.self::UNIQUE_INDEX);
            $this->rebuildSqliteCouponUsages(false);

            return;
        }

        DB::statement('DROP TRIGGER '.self::INVENTORY_INSERT_TRIGGER);
        DB::statement('ALTER TABLE order_status_histories DROP INDEX '.self::EVENT_UNIQUE_INDEX);
        DB::statement('ALTER TABLE inventory_transactions DROP INDEX '.self::UNIQUE_INDEX);
        DB::statement('DROP TRIGGER IF EXISTS coupon_usages_lifecycle_update');
        DB::statement('ALTER TABLE coupon_usages DROP CONSTRAINT coupon_usages_state_check');
        DB::statement('ALTER TABLE coupon_usages ADD CONSTRAINT coupon_usages_state_check CHECK ('.$this->couponStateCheck(false).')');
        $this->createMariaDbCouponTrigger(false);
    }

    private function couponStateCheck(bool $allowCodRelease = true): string
    {
        $released = "(BINARY status = 'released' AND order_id IS NULL AND consumed_at IS NULL AND released_at IS NOT NULL AND expires_at IS NOT NULL AND late_callback_exception = 0)";
        if ($allowCodRelease) {
            $released .= " OR (BINARY status = 'released' AND payment_attempt_id IS NULL AND order_id IS NOT NULL AND reserved_at IS NULL AND expires_at IS NULL AND consumed_at IS NOT NULL AND released_at IS NOT NULL AND late_callback_exception = 0)";
        }

        return "(BINARY status = 'reserved' AND payment_attempt_id IS NOT NULL AND order_id IS NULL AND consumed_at IS NULL AND released_at IS NULL AND expires_at IS NOT NULL AND late_callback_exception = 0)
            OR {$released}
            OR (BINARY status = 'consumed' AND order_id IS NOT NULL AND consumed_at IS NOT NULL AND released_at IS NULL AND late_callback_exception = 0)
            OR (BINARY status = 'consumed' AND payment_attempt_id IS NOT NULL AND order_id IS NOT NULL AND consumed_at IS NOT NULL AND released_at IS NOT NULL AND late_callback_exception = 1)";
    }

    private function createMariaDbCouponTrigger(bool $allowCodRelease): void
    {
        $codRelease = $allowCodRelease
            ? " OR (BINARY OLD.status = 'consumed' AND BINARY NEW.status = 'released'
                    AND OLD.payment_attempt_id IS NULL AND NEW.payment_attempt_id IS NULL
                    AND NEW.order_id <=> OLD.order_id AND NEW.consumed_at <=> OLD.consumed_at
                    AND NEW.released_at IS NOT NULL AND NEW.late_callback_exception = 0
                    AND EXISTS (
                        SELECT 1 FROM orders terminal_order
                        WHERE terminal_order.id = NEW.order_id
                          AND terminal_order.user_id = NEW.customer_id
                          AND terminal_order.coupon_id = NEW.coupon_id
                          AND BINARY terminal_order.payment_method = 'cod'
                          AND BINARY terminal_order.payment_status = 'chua_thanh_toan'
                          AND BINARY terminal_order.status = 'da_huy'
                          AND terminal_order.delivered_at IS NULL
                    )
                    AND EXISTS (SELECT 1 FROM order_items terminal_item WHERE terminal_item.order_id = NEW.order_id)
                    AND NOT EXISTS (
                        SELECT 1
                        FROM order_items terminal_item
                        LEFT JOIN return_inspections terminal_inspection
                          ON terminal_inspection.order_item_id = terminal_item.id
                        LEFT JOIN inventory_transactions terminal_ledger
                          ON terminal_ledger.order_item_id = terminal_item.id
                         AND BINARY terminal_ledger.type = 'cancel_restore'
                        WHERE terminal_item.order_id = NEW.order_id
                          AND (
                            terminal_inspection.id IS NULL
                            OR terminal_inspection.inspected_by IS NULL
                            OR terminal_inspection.inspected_at IS NULL
                            OR terminal_ledger.id IS NULL
                            OR terminal_ledger.return_inspection_id <> terminal_inspection.id
                            OR terminal_ledger.product_id <> terminal_item.product_id
                            OR terminal_ledger.sellable_delta <> terminal_inspection.sellable_quantity
                            OR terminal_ledger.damaged_delta <> terminal_inspection.damaged_quantity
                          )
                    ))"
            : '';

        DB::unprepared("CREATE TRIGGER coupon_usages_lifecycle_update BEFORE UPDATE ON coupon_usages
            FOR EACH ROW BEGIN
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
                       {$codRelease}
                   ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'coupon_usages invalid lifecycle transition';
                END IF;
            END");
    }

    private function rebuildSqliteCouponUsages(bool $allowCodRelease): void
    {
        DB::statement('DROP TRIGGER IF EXISTS coupon_usages_lifecycle_update');
        DB::statement('DROP TRIGGER IF EXISTS coupon_usages_no_delete');
        DB::statement('ALTER TABLE coupon_usages RENAME TO coupon_usages_terminal_old');

        $stateCheck = str_replace('BINARY ', '', $this->couponStateCheck($allowCodRelease));
        DB::statement("CREATE TABLE coupon_usages (
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
            CONSTRAINT coupon_usages_state_check CHECK ({$stateCheck}),
            CONSTRAINT coupon_usages_time_check CHECK (
                (reserved_at IS NULL OR expires_at IS NULL OR expires_at >= reserved_at)
                AND (reserved_at IS NULL OR consumed_at IS NULL OR consumed_at >= reserved_at)
                AND (reserved_at IS NULL OR released_at IS NULL OR released_at >= reserved_at)
            )
        )");
        DB::statement('INSERT INTO coupon_usages SELECT * FROM coupon_usages_terminal_old');
        Schema::drop('coupon_usages_terminal_old');
        DB::statement('CREATE INDEX coupon_usages_capacity_index ON coupon_usages (coupon_id, status, expires_at)');
        DB::statement('CREATE INDEX coupon_usages_customer_capacity_index ON coupon_usages (coupon_id, customer_id, status, expires_at)');
        DB::statement('CREATE INDEX coupon_usages_expiration_index ON coupon_usages (status, expires_at)');
        $this->createSqliteCouponTriggers($allowCodRelease);
    }

    private function createSqliteCouponTriggers(bool $allowCodRelease): void
    {
        $codRelease = $allowCodRelease
            ? " OR (OLD.status = 'consumed' AND NEW.status = 'released'
                    AND OLD.payment_attempt_id IS NULL AND NEW.payment_attempt_id IS NULL
                    AND NEW.order_id IS OLD.order_id AND NEW.consumed_at IS OLD.consumed_at
                    AND NEW.released_at IS NOT NULL AND NEW.late_callback_exception = 0
                    AND EXISTS (
                        SELECT 1 FROM orders terminal_order
                        WHERE terminal_order.id = NEW.order_id
                          AND terminal_order.user_id = NEW.customer_id
                          AND terminal_order.coupon_id = NEW.coupon_id
                          AND terminal_order.payment_method = 'cod'
                          AND terminal_order.payment_status = 'chua_thanh_toan'
                          AND terminal_order.status = 'da_huy'
                          AND terminal_order.delivered_at IS NULL
                    )
                    AND EXISTS (SELECT 1 FROM order_items terminal_item WHERE terminal_item.order_id = NEW.order_id)
                    AND NOT EXISTS (
                        SELECT 1
                        FROM order_items terminal_item
                        LEFT JOIN return_inspections terminal_inspection
                          ON terminal_inspection.order_item_id = terminal_item.id
                        LEFT JOIN inventory_transactions terminal_ledger
                          ON terminal_ledger.order_item_id = terminal_item.id
                         AND terminal_ledger.type = 'cancel_restore'
                        WHERE terminal_item.order_id = NEW.order_id
                          AND (
                            terminal_inspection.id IS NULL
                            OR terminal_inspection.inspected_by IS NULL
                            OR terminal_inspection.inspected_at IS NULL
                            OR terminal_ledger.id IS NULL
                            OR terminal_ledger.return_inspection_id <> terminal_inspection.id
                            OR terminal_ledger.product_id <> terminal_item.product_id
                            OR terminal_ledger.sellable_delta <> terminal_inspection.sellable_quantity
                            OR terminal_ledger.damaged_delta <> terminal_inspection.damaged_quantity
                          )
                    ))"
            : '';
        DB::statement("CREATE TRIGGER coupon_usages_lifecycle_update BEFORE UPDATE ON coupon_usages
            FOR EACH ROW WHEN NEW.coupon_id <> OLD.coupon_id
              OR NEW.customer_id <> OLD.customer_id
              OR COALESCE(NEW.payment_attempt_id, -1) <> COALESCE(OLD.payment_attempt_id, -1)
              OR COALESCE(NEW.reserved_at, '') <> COALESCE(OLD.reserved_at, '')
              OR COALESCE(NEW.expires_at, '') <> COALESCE(OLD.expires_at, '')
              OR NEW.created_at <> OLD.created_at
              OR NOT (
                (OLD.status = 'reserved' AND NEW.status = 'released')
                OR (OLD.status = 'reserved' AND NEW.status = 'consumed' AND NEW.late_callback_exception = 0)
                OR (OLD.status = 'released' AND NEW.status = 'consumed' AND NEW.late_callback_exception = 1 AND NEW.released_at = OLD.released_at)
                {$codRelease}
              )
            BEGIN SELECT RAISE(ABORT, 'coupon_usages invalid lifecycle transition'); END");
        DB::statement("CREATE TRIGGER coupon_usages_no_delete BEFORE DELETE ON coupon_usages
            BEGIN SELECT RAISE(ABORT, 'coupon_usages cannot be deleted'); END");
    }

    private function createMariaDbInventoryInsertGuard(): void
    {
        DB::unprepared('CREATE TRIGGER '.self::INVENTORY_INSERT_TRIGGER." BEFORE INSERT ON inventory_transactions
            FOR EACH ROW BEGIN
                IF BINARY NEW.type = 'cancel_restore' AND (
                    NEW.order_item_id IS NULL OR NEW.return_inspection_id IS NULL
                    OR NEW.sellable_delta < 0 OR NEW.damaged_delta < 0
                    OR NOT EXISTS (
                        SELECT 1
                        FROM order_items terminal_item
                        JOIN return_inspections terminal_inspection
                          ON terminal_inspection.order_item_id = terminal_item.id
                        WHERE terminal_item.id = NEW.order_item_id
                          AND terminal_item.product_id = NEW.product_id
                          AND terminal_inspection.id = NEW.return_inspection_id
                          AND terminal_inspection.inspected_by IS NOT NULL
                          AND terminal_inspection.inspected_at IS NOT NULL
                          AND terminal_inspection.sellable_quantity = NEW.sellable_delta
                          AND terminal_inspection.damaged_quantity = NEW.damaged_delta
                          AND terminal_inspection.sellable_quantity + terminal_inspection.damaged_quantity = terminal_item.quantity
                    )
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cancel_restore must match one completed Return Inspection';
                END IF;
            END");
    }

    private function createSqliteInventoryInsertGuard(): void
    {
        DB::statement('CREATE TRIGGER '.self::INVENTORY_INSERT_TRIGGER." BEFORE INSERT ON inventory_transactions
            FOR EACH ROW WHEN NEW.type = 'cancel_restore' AND (
                NEW.order_item_id IS NULL OR NEW.return_inspection_id IS NULL
                OR NEW.sellable_delta < 0 OR NEW.damaged_delta < 0
                OR NOT EXISTS (
                    SELECT 1
                    FROM order_items terminal_item
                    JOIN return_inspections terminal_inspection
                      ON terminal_inspection.order_item_id = terminal_item.id
                    WHERE terminal_item.id = NEW.order_item_id
                      AND terminal_item.product_id = NEW.product_id
                      AND terminal_inspection.id = NEW.return_inspection_id
                      AND terminal_inspection.inspected_by IS NOT NULL
                      AND terminal_inspection.inspected_at IS NOT NULL
                      AND terminal_inspection.sellable_quantity = NEW.sellable_delta
                      AND terminal_inspection.damaged_quantity = NEW.damaged_delta
                      AND terminal_inspection.sellable_quantity + terminal_inspection.damaged_quantity = terminal_item.quantity
                )
            ) BEGIN
                SELECT RAISE(ABORT, 'cancel_restore must match one completed Return Inspection');
            END");
    }

    private function hasInventoryBoundary(): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'index')->where('name', self::UNIQUE_INDEX)->exists();
        }

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'inventory_transactions')
            ->where('INDEX_NAME', self::UNIQUE_INDEX)
            ->exists();
    }

    private function hasEventBoundary(): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'index')->where('name', self::EVENT_UNIQUE_INDEX)->exists();
        }

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'order_status_histories')
            ->where('INDEX_NAME', self::EVENT_UNIQUE_INDEX)
            ->exists();
    }

    private function hasInventoryInsertGuard(): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'trigger')->where('name', self::INVENTORY_INSERT_TRIGGER)->exists();
        }

        return DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', self::INVENTORY_INSERT_TRIGGER)
            ->exists();
    }

    private function couponSchemaAllowsCodRelease(): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', 'coupon_usages')->value('sql');
        } else {
            $row = DB::selectOne('SHOW CREATE TABLE coupon_usages');
            $sql = $row === null ? null : array_values((array) $row)[1];
        }

        if (! is_string($sql)) {
            return false;
        }

        $normalizedSql = preg_replace('/\s+/', ' ', strtolower(str_replace('`', '', $sql)));

        return is_string($normalizedSql)
            && str_contains(
                $normalizedSql,
                "= 'released' and payment_attempt_id is null and order_id is not null"
            );
    }

    private function couponTriggerAllowsCodRelease(): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $sql = DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->where('name', 'coupon_usages_lifecycle_update')
                ->value('sql');
        } else {
            $sql = DB::table('information_schema.TRIGGERS')
                ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->where('TRIGGER_NAME', 'coupon_usages_lifecycle_update')
                ->value('ACTION_STATEMENT');
        }

        if (! is_string($sql)) {
            return false;
        }

        $normalizedSql = strtolower(str_replace('`', '', $sql));

        return str_contains($normalizedSql, 'terminal_order')
            && str_contains($normalizedSql, 'terminal_ledger')
            && str_contains($normalizedSql, "status = 'da_huy'");
    }

    private function assertDependencies(): void
    {
        foreach (['inventory_transactions', 'coupon_usages', 'orders', 'order_items', 'order_status_histories', 'return_inspections'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('COD terminal lifecycle migration requires the '.$table.' table.');
            }
        }
    }

    private function assertSupportedDatabase(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('COD terminal lifecycle migration supports only SQLite, MariaDB and MySQL.');
        }
    }
};
