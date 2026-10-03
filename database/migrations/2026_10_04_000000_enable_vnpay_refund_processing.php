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
        foreach (['refunds', 'payment_attempts', 'coupon_usages', 'users'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("VNPay refund processing migration requires {$table}.");
            }
        }
        if (Schema::hasTable('refund_gateway_attempts')) {
            throw new RuntimeException('VNPay refund processing migration found a partial schema state.');
        }

        Schema::create('refund_gateway_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->unique('refund_gateway_attempts_refund_unique')->constrained('refunds')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->uuid('submission_event_key')->unique('refund_gateway_attempts_submission_event_unique');
            $table->char('request_id', 32)->unique('refund_gateway_attempts_request_unique');
            $table->char('request_fingerprint', 64);
            $table->unsignedBigInteger('amount_vnd');
            $table->dateTime('submitted_at', 6);
            $table->string('response_code', 2)->nullable();
            $table->string('transaction_status', 2)->nullable();
            $table->string('gateway_reference', 100)->nullable()->unique('refund_gateway_attempts_gateway_reference_unique');
            $table->char('response_fingerprint', 64)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->string('status', 16)->default('submitted');
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->uuid('reconciliation_event_key')->nullable()->unique('refund_gateway_attempts_reconciliation_event_unique');
            $table->char('reconciliation_fingerprint', 64)->nullable();
            $table->string('reconciliation_note', 500)->nullable();
            $table->dateTime('reconciled_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['status', 'submitted_at'], 'refund_gateway_attempts_status_submitted_index');
        });

        $this->createGatewayAttemptGuards();
        $this->replaceCouponLifecycle(true);
    }

    public function down(): void
    {
        $this->assertSupportedDatabase();
        if (! Schema::hasTable('refund_gateway_attempts')) {
            throw new RuntimeException('VNPay refund processing rollback found no gateway attempt table.');
        }
        if (DB::table('refund_gateway_attempts')->exists()
            || DB::table('coupon_usages')->where('release_reason', 'vnpay_refund_succeeded')->exists()) {
            throw new RuntimeException('VNPay refund processing rollback cannot remove existing refund evidence.');
        }

        $this->replaceCouponLifecycle(false);
        DB::statement('DROP TRIGGER IF EXISTS refund_gateway_attempts_insert_check');
        DB::statement('DROP TRIGGER IF EXISTS refund_gateway_attempts_update_guard');
        DB::statement('DROP TRIGGER IF EXISTS refund_gateway_attempts_delete_guard');
        Schema::drop('refund_gateway_attempts');
    }

    private function createGatewayAttemptGuards(): void
    {
        $shape = $this->gatewayAttemptShapeCheck();
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE refund_gateway_attempts ADD CONSTRAINT refund_gateway_attempts_shape_check CHECK ({$shape})");
            DB::unprepared("CREATE TRIGGER refund_gateway_attempts_update_guard BEFORE UPDATE ON refund_gateway_attempts
                FOR EACH ROW BEGIN
                    IF NOT (NEW.refund_id <=> OLD.refund_id
                        AND NEW.submitted_by <=> OLD.submitted_by
                        AND NEW.submission_event_key <=> OLD.submission_event_key
                        AND BINARY NEW.request_id <=> BINARY OLD.request_id
                        AND BINARY NEW.request_fingerprint <=> BINARY OLD.request_fingerprint
                        AND NEW.amount_vnd <=> OLD.amount_vnd
                        AND NEW.submitted_at <=> OLD.submitted_at
                        AND NEW.created_at <=> OLD.created_at
                        AND (
                            (BINARY OLD.status = 'submitted' AND BINARY NEW.status IN ('succeeded','failed','ambiguous')
                                AND NEW.reconciled_by IS NULL AND NEW.reconciliation_event_key IS NULL
                                AND NEW.reconciliation_fingerprint IS NULL AND NEW.reconciliation_note IS NULL
                                AND NEW.reconciled_at IS NULL)
                            OR (BINARY OLD.status = 'ambiguous' AND BINARY NEW.status IN ('succeeded','failed')
                                AND ((NEW.reconciled_by IS NULL AND NEW.reconciliation_event_key IS NULL
                                        AND NEW.reconciliation_fingerprint IS NULL AND NEW.reconciliation_note IS NULL
                                        AND NEW.reconciled_at IS NULL)
                                    OR (NEW.reconciled_by IS NOT NULL AND NEW.reconciliation_event_key IS NOT NULL
                                        AND NEW.reconciliation_fingerprint IS NOT NULL AND NEW.reconciliation_note IS NOT NULL
                                        AND NEW.reconciliation_note <> '' AND NEW.reconciled_at IS NOT NULL)))
                        )) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refund gateway attempt lifecycle is immutable';
                    END IF;
                END");
            DB::unprepared("CREATE TRIGGER refund_gateway_attempts_delete_guard BEFORE DELETE ON refund_gateway_attempts
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refund gateway attempts cannot be deleted'");

            return;
        }

        $sqliteShape = $this->sqliteGatewayAttemptShapeCheck();
        DB::unprepared("CREATE TRIGGER refund_gateway_attempts_insert_check BEFORE INSERT ON refund_gateway_attempts
            WHEN NOT ({$sqliteShape}) BEGIN SELECT RAISE(ABORT, 'refund gateway attempt shape check failed'); END");
        DB::unprepared("CREATE TRIGGER refund_gateway_attempts_update_guard BEFORE UPDATE ON refund_gateway_attempts
            WHEN NOT (NEW.refund_id IS OLD.refund_id
                AND NEW.submitted_by IS OLD.submitted_by
                AND NEW.submission_event_key IS OLD.submission_event_key
                AND NEW.request_id IS OLD.request_id
                AND NEW.request_fingerprint IS OLD.request_fingerprint
                AND NEW.amount_vnd IS OLD.amount_vnd
                AND NEW.submitted_at IS OLD.submitted_at
                AND NEW.created_at IS OLD.created_at
                AND ({$sqliteShape})
                AND (
                    (OLD.status = 'submitted' AND NEW.status IN ('succeeded','failed','ambiguous')
                        AND NEW.reconciled_by IS NULL AND NEW.reconciliation_event_key IS NULL
                        AND NEW.reconciliation_fingerprint IS NULL AND NEW.reconciliation_note IS NULL
                        AND NEW.reconciled_at IS NULL)
                    OR (OLD.status = 'ambiguous' AND NEW.status IN ('succeeded','failed')
                        AND ((NEW.reconciled_by IS NULL AND NEW.reconciliation_event_key IS NULL
                                AND NEW.reconciliation_fingerprint IS NULL AND NEW.reconciliation_note IS NULL
                                AND NEW.reconciled_at IS NULL)
                            OR (NEW.reconciled_by IS NOT NULL AND NEW.reconciliation_event_key IS NOT NULL
                                AND NEW.reconciliation_fingerprint IS NOT NULL AND NEW.reconciliation_note IS NOT NULL
                                AND NEW.reconciliation_note <> '' AND NEW.reconciled_at IS NOT NULL)))
                ))
            BEGIN SELECT RAISE(ABORT, 'refund gateway attempt lifecycle is immutable'); END");
        DB::unprepared("CREATE TRIGGER refund_gateway_attempts_delete_guard BEFORE DELETE ON refund_gateway_attempts
            BEGIN SELECT RAISE(ABORT, 'refund gateway attempts cannot be deleted'); END");
    }

    private function gatewayAttemptShapeCheck(): string
    {
        return "amount_vnd > 0
            AND request_id REGEXP BINARY '^RF[0-9A-F]{30}$'
            AND request_fingerprint REGEXP BINARY '^[0-9a-f]{64}$'
            AND BINARY status IN ('submitted','succeeded','failed','ambiguous')
            AND (response_code IS NULL OR response_code REGEXP BINARY '^[0-9]{2}$')
            AND (transaction_status IS NULL OR transaction_status REGEXP BINARY '^[0-9]{2}$')
            AND (response_fingerprint IS NULL OR response_fingerprint REGEXP BINARY '^[0-9a-f]{64}$')
            AND (reconciliation_fingerprint IS NULL OR reconciliation_fingerprint REGEXP BINARY '^[0-9a-f]{64}$')
            AND (completed_at IS NULL OR completed_at >= submitted_at)
            AND (reconciled_at IS NULL OR reconciled_at >= submitted_at)
            AND (
                (BINARY status = 'submitted' AND completed_at IS NULL
                    AND response_code IS NULL AND transaction_status IS NULL
                    AND gateway_reference IS NULL AND response_fingerprint IS NULL
                    AND reconciled_by IS NULL AND reconciliation_event_key IS NULL
                    AND reconciliation_fingerprint IS NULL AND reconciliation_note IS NULL AND reconciled_at IS NULL)
                OR (BINARY status = 'ambiguous' AND completed_at IS NOT NULL
                    AND reconciled_by IS NULL AND reconciliation_event_key IS NULL
                    AND reconciliation_fingerprint IS NULL AND reconciliation_note IS NULL AND reconciled_at IS NULL)
                OR (BINARY status = 'succeeded' AND completed_at IS NOT NULL
                    AND ((BINARY response_code = '00' AND BINARY transaction_status = '00'
                            AND gateway_reference IS NOT NULL AND gateway_reference <> ''
                            AND response_fingerprint IS NOT NULL
                            AND reconciled_by IS NULL AND reconciliation_event_key IS NULL
                            AND reconciliation_fingerprint IS NULL AND reconciliation_note IS NULL AND reconciled_at IS NULL)
                        OR (reconciled_by IS NOT NULL AND reconciliation_event_key IS NOT NULL
                            AND reconciliation_fingerprint IS NOT NULL AND reconciliation_note IS NOT NULL
                            AND reconciliation_note <> '' AND reconciled_at IS NOT NULL)))
                OR (BINARY status = 'failed' AND completed_at IS NOT NULL
                    AND ((response_fingerprint IS NOT NULL
                            AND (BINARY response_code IN ('02','03','04','13','16','91','93','95','97')
                                OR (BINARY response_code = '00' AND BINARY transaction_status = '09'))
                            AND reconciled_by IS NULL AND reconciliation_event_key IS NULL
                            AND reconciliation_fingerprint IS NULL AND reconciliation_note IS NULL AND reconciled_at IS NULL)
                        OR (reconciled_by IS NOT NULL AND reconciliation_event_key IS NOT NULL
                            AND reconciliation_fingerprint IS NOT NULL AND reconciliation_note IS NOT NULL
                            AND reconciliation_note <> '' AND reconciled_at IS NOT NULL)))
            )";
    }

    private function sqliteGatewayAttemptShapeCheck(): string
    {
        $hexFingerprint = static fn (string $column): string => "(NEW.{$column} IS NULL OR (LENGTH(NEW.{$column}) = 64 AND NEW.{$column} NOT GLOB '*[^0-9a-f]*'))";

        return "NEW.amount_vnd > 0
            AND LENGTH(NEW.request_id) = 32 AND SUBSTR(NEW.request_id, 1, 2) = 'RF'
            AND SUBSTR(NEW.request_id, 3) NOT GLOB '*[^0-9A-F]*'
            AND LENGTH(NEW.request_fingerprint) = 64 AND NEW.request_fingerprint NOT GLOB '*[^0-9a-f]*'
            AND NEW.status IN ('submitted','succeeded','failed','ambiguous')
            AND (NEW.response_code IS NULL OR (LENGTH(NEW.response_code) = 2 AND NEW.response_code NOT GLOB '*[^0-9]*'))
            AND (NEW.transaction_status IS NULL OR (LENGTH(NEW.transaction_status) = 2 AND NEW.transaction_status NOT GLOB '*[^0-9]*'))
            AND {$hexFingerprint('response_fingerprint')}
            AND {$hexFingerprint('reconciliation_fingerprint')}
            AND (NEW.completed_at IS NULL OR NEW.completed_at >= NEW.submitted_at)
            AND (NEW.reconciled_at IS NULL OR NEW.reconciled_at >= NEW.submitted_at)
            AND (
                (NEW.status = 'submitted' AND NEW.completed_at IS NULL
                    AND NEW.response_code IS NULL AND NEW.transaction_status IS NULL
                    AND NEW.gateway_reference IS NULL AND NEW.response_fingerprint IS NULL
                    AND NEW.reconciled_by IS NULL AND NEW.reconciliation_event_key IS NULL
                    AND NEW.reconciliation_fingerprint IS NULL AND NEW.reconciliation_note IS NULL AND NEW.reconciled_at IS NULL)
                OR (NEW.status = 'ambiguous' AND NEW.completed_at IS NOT NULL
                    AND NEW.reconciled_by IS NULL AND NEW.reconciliation_event_key IS NULL
                    AND NEW.reconciliation_fingerprint IS NULL AND NEW.reconciliation_note IS NULL AND NEW.reconciled_at IS NULL)
                OR (NEW.status = 'succeeded' AND NEW.completed_at IS NOT NULL
                    AND ((NEW.response_code = '00' AND NEW.transaction_status = '00'
                            AND NEW.gateway_reference IS NOT NULL AND NEW.gateway_reference <> ''
                            AND NEW.response_fingerprint IS NOT NULL
                            AND NEW.reconciled_by IS NULL AND NEW.reconciliation_event_key IS NULL
                            AND NEW.reconciliation_fingerprint IS NULL AND NEW.reconciliation_note IS NULL AND NEW.reconciled_at IS NULL)
                        OR (NEW.reconciled_by IS NOT NULL AND NEW.reconciliation_event_key IS NOT NULL
                            AND NEW.reconciliation_fingerprint IS NOT NULL AND NEW.reconciliation_note IS NOT NULL
                            AND NEW.reconciliation_note <> '' AND NEW.reconciled_at IS NOT NULL)))
                OR (NEW.status = 'failed' AND NEW.completed_at IS NOT NULL
                    AND ((NEW.response_fingerprint IS NOT NULL
                            AND (NEW.response_code IN ('02','03','04','13','16','91','93','95','97')
                                OR (NEW.response_code = '00' AND NEW.transaction_status = '09'))
                            AND NEW.reconciled_by IS NULL AND NEW.reconciliation_event_key IS NULL
                            AND NEW.reconciliation_fingerprint IS NULL AND NEW.reconciliation_note IS NULL AND NEW.reconciled_at IS NULL)
                        OR (NEW.reconciled_by IS NOT NULL AND NEW.reconciliation_event_key IS NOT NULL
                            AND NEW.reconciliation_fingerprint IS NOT NULL AND NEW.reconciliation_note IS NOT NULL
                            AND NEW.reconciliation_note <> '' AND NEW.reconciled_at IS NOT NULL)))
            )";
    }

    private function replaceCouponLifecycle(bool $allowRefundRelease): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteCouponUsages($allowRefundRelease);

            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS coupon_usages_lifecycle_update');
        DB::statement('ALTER TABLE coupon_usages DROP CONSTRAINT coupon_usages_state_check');
        DB::statement('ALTER TABLE coupon_usages ADD CONSTRAINT coupon_usages_state_check CHECK ('.$this->couponStateCheck($allowRefundRelease).')');
        $this->createMariaDbCouponTrigger($allowRefundRelease);
    }

    private function couponStateCheck(bool $allowRefundRelease): string
    {
        $refundRelease = $allowRefundRelease
            ? " OR (BINARY status = 'released' AND payment_attempt_id IS NOT NULL AND order_id IS NOT NULL
                AND consumed_at IS NOT NULL AND released_at IS NOT NULL AND late_callback_exception IN (0,1))"
            : '';

        return "(BINARY status = 'reserved' AND payment_attempt_id IS NOT NULL AND order_id IS NULL
                AND consumed_at IS NULL AND released_at IS NULL AND expires_at IS NOT NULL AND late_callback_exception = 0)
            OR (BINARY status = 'released' AND order_id IS NULL AND consumed_at IS NULL
                AND released_at IS NOT NULL AND expires_at IS NOT NULL AND late_callback_exception = 0)
            OR (BINARY status = 'released' AND payment_attempt_id IS NULL AND order_id IS NOT NULL
                AND reserved_at IS NULL AND expires_at IS NULL AND consumed_at IS NOT NULL
                AND released_at IS NOT NULL AND late_callback_exception = 0)
            {$refundRelease}
            OR (BINARY status = 'consumed' AND order_id IS NOT NULL AND consumed_at IS NOT NULL
                AND released_at IS NULL AND late_callback_exception = 0)
            OR (BINARY status = 'consumed' AND payment_attempt_id IS NOT NULL AND order_id IS NOT NULL
                AND consumed_at IS NOT NULL AND released_at IS NOT NULL AND late_callback_exception = 1)";
    }

    private function createMariaDbCouponTrigger(bool $allowRefundRelease): void
    {
        $refundRelease = $allowRefundRelease ? " OR (BINARY OLD.status = 'consumed' AND BINARY NEW.status = 'released'
                AND NEW.payment_attempt_id <=> OLD.payment_attempt_id AND NEW.order_id <=> OLD.order_id
                AND NEW.consumed_at <=> OLD.consumed_at AND NEW.released_at IS NOT NULL
                AND EXISTS (
                    SELECT 1 FROM refunds successful_refund
                    JOIN refund_gateway_attempts successful_gateway ON successful_gateway.refund_id = successful_refund.id
                    JOIN payment_attempts refunded_attempt ON refunded_attempt.id = successful_refund.payment_attempt_id
                    LEFT JOIN orders refunded_order ON refunded_order.id = successful_refund.order_id
                    WHERE successful_refund.payment_attempt_id = NEW.payment_attempt_id
                      AND BINARY successful_refund.status = 'succeeded'
                      AND BINARY successful_gateway.status = 'succeeded'
                      AND BINARY refunded_attempt.status = 'hoan_tien'
                      AND refunded_attempt.user_id = NEW.customer_id
                      AND (successful_refund.order_id IS NULL OR successful_refund.order_id = NEW.order_id)
                      AND (refunded_order.id IS NULL OR (refunded_order.user_id = NEW.customer_id AND refunded_order.coupon_id = NEW.coupon_id))
                ))" : '';

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
                       OR (BINARY OLD.status = 'consumed' AND BINARY NEW.status = 'released'
                            AND OLD.payment_attempt_id IS NULL AND NEW.payment_attempt_id IS NULL
                            AND NEW.order_id <=> OLD.order_id AND NEW.consumed_at <=> OLD.consumed_at
                            AND NEW.released_at IS NOT NULL AND NEW.late_callback_exception = 0
                            AND EXISTS (SELECT 1 FROM orders terminal_order WHERE terminal_order.id = NEW.order_id
                                AND terminal_order.user_id = NEW.customer_id AND terminal_order.coupon_id = NEW.coupon_id
                                AND BINARY terminal_order.payment_method = 'cod' AND BINARY terminal_order.payment_status = 'chua_thanh_toan'
                                AND BINARY terminal_order.status = 'da_huy' AND terminal_order.delivered_at IS NULL)
                            AND EXISTS (SELECT 1 FROM order_items terminal_item WHERE terminal_item.order_id = NEW.order_id)
                            AND NOT EXISTS (SELECT 1 FROM order_items terminal_item
                                LEFT JOIN return_inspections terminal_inspection ON terminal_inspection.order_item_id = terminal_item.id
                                LEFT JOIN inventory_transactions terminal_ledger ON terminal_ledger.order_item_id = terminal_item.id
                                    AND BINARY terminal_ledger.type = 'cancel_restore'
                                WHERE terminal_item.order_id = NEW.order_id AND (terminal_inspection.id IS NULL
                                    OR terminal_inspection.inspected_by IS NULL OR terminal_inspection.inspected_at IS NULL
                                    OR terminal_ledger.id IS NULL OR terminal_ledger.return_inspection_id <> terminal_inspection.id
                                    OR terminal_ledger.product_id <> terminal_item.product_id
                                    OR terminal_ledger.sellable_delta <> terminal_inspection.sellable_quantity
                                    OR terminal_ledger.damaged_delta <> terminal_inspection.damaged_quantity)))
                       {$refundRelease}
                    ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'coupon_usages invalid lifecycle transition';
                END IF;
            END");
    }

    private function rebuildSqliteCouponUsages(bool $allowRefundRelease): void
    {
        DB::statement('DROP TRIGGER IF EXISTS coupon_usages_lifecycle_update');
        DB::statement('DROP TRIGGER IF EXISTS coupon_usages_no_delete');
        DB::statement('ALTER TABLE coupon_usages RENAME TO coupon_usages_refund_old');
        $stateCheck = str_replace(['BINARY ', ' REGEXP BINARY '], ['', ' REGEXP '], $this->couponStateCheck($allowRefundRelease));
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
            CONSTRAINT coupon_usages_time_check CHECK ((reserved_at IS NULL OR expires_at IS NULL OR expires_at >= reserved_at)
                AND (reserved_at IS NULL OR consumed_at IS NULL OR consumed_at >= reserved_at)
                AND (reserved_at IS NULL OR released_at IS NULL OR released_at >= reserved_at))
        )");
        DB::statement('INSERT INTO coupon_usages SELECT * FROM coupon_usages_refund_old');
        Schema::drop('coupon_usages_refund_old');
        DB::statement('CREATE INDEX coupon_usages_capacity_index ON coupon_usages (coupon_id, status, expires_at)');
        DB::statement('CREATE INDEX coupon_usages_customer_capacity_index ON coupon_usages (coupon_id, customer_id, status, expires_at)');
        DB::statement('CREATE INDEX coupon_usages_expiration_index ON coupon_usages (status, expires_at)');
        $this->createSqliteCouponTriggers($allowRefundRelease);
    }

    private function createSqliteCouponTriggers(bool $allowRefundRelease): void
    {
        $refundRelease = $allowRefundRelease ? " OR (OLD.status = 'consumed' AND NEW.status = 'released'
                AND NEW.payment_attempt_id IS OLD.payment_attempt_id AND NEW.order_id IS OLD.order_id
                AND NEW.consumed_at IS OLD.consumed_at AND NEW.released_at IS NOT NULL
                AND EXISTS (SELECT 1 FROM refunds successful_refund
                    JOIN refund_gateway_attempts successful_gateway ON successful_gateway.refund_id = successful_refund.id
                    JOIN payment_attempts refunded_attempt ON refunded_attempt.id = successful_refund.payment_attempt_id
                    LEFT JOIN orders refunded_order ON refunded_order.id = successful_refund.order_id
                    WHERE successful_refund.payment_attempt_id = NEW.payment_attempt_id
                      AND successful_refund.status = 'succeeded' AND successful_gateway.status = 'succeeded'
                      AND refunded_attempt.status = 'hoan_tien' AND refunded_attempt.user_id = NEW.customer_id
                      AND (successful_refund.order_id IS NULL OR successful_refund.order_id = NEW.order_id)
                      AND (refunded_order.id IS NULL OR (refunded_order.user_id = NEW.customer_id AND refunded_order.coupon_id = NEW.coupon_id))))" : '';

        DB::statement("CREATE TRIGGER coupon_usages_lifecycle_update BEFORE UPDATE ON coupon_usages
            FOR EACH ROW WHEN NEW.coupon_id <> OLD.coupon_id OR NEW.customer_id <> OLD.customer_id
              OR COALESCE(NEW.payment_attempt_id, -1) <> COALESCE(OLD.payment_attempt_id, -1)
              OR COALESCE(NEW.reserved_at, '') <> COALESCE(OLD.reserved_at, '')
              OR COALESCE(NEW.expires_at, '') <> COALESCE(OLD.expires_at, '') OR NEW.created_at <> OLD.created_at
              OR NOT ((OLD.status = 'reserved' AND NEW.status = 'released')
                OR (OLD.status = 'reserved' AND NEW.status = 'consumed' AND NEW.late_callback_exception = 0)
                OR (OLD.status = 'released' AND NEW.status = 'consumed' AND NEW.late_callback_exception = 1 AND NEW.released_at = OLD.released_at)
                OR (OLD.status = 'consumed' AND NEW.status = 'released' AND OLD.payment_attempt_id IS NULL
                    AND NEW.payment_attempt_id IS NULL AND NEW.order_id IS OLD.order_id AND NEW.consumed_at IS OLD.consumed_at
                    AND NEW.released_at IS NOT NULL AND NEW.late_callback_exception = 0
                    AND EXISTS (SELECT 1 FROM orders terminal_order WHERE terminal_order.id = NEW.order_id
                        AND terminal_order.user_id = NEW.customer_id AND terminal_order.coupon_id = NEW.coupon_id
                        AND terminal_order.payment_method = 'cod' AND terminal_order.payment_status = 'chua_thanh_toan'
                        AND terminal_order.status = 'da_huy' AND terminal_order.delivered_at IS NULL)
                    AND EXISTS (SELECT 1 FROM order_items terminal_item WHERE terminal_item.order_id = NEW.order_id)
                    AND NOT EXISTS (SELECT 1 FROM order_items terminal_item
                        LEFT JOIN return_inspections terminal_inspection ON terminal_inspection.order_item_id = terminal_item.id
                        LEFT JOIN inventory_transactions terminal_ledger ON terminal_ledger.order_item_id = terminal_item.id AND terminal_ledger.type = 'cancel_restore'
                        WHERE terminal_item.order_id = NEW.order_id AND (terminal_inspection.id IS NULL
                            OR terminal_inspection.inspected_by IS NULL OR terminal_inspection.inspected_at IS NULL
                            OR terminal_ledger.id IS NULL OR terminal_ledger.return_inspection_id <> terminal_inspection.id
                            OR terminal_ledger.product_id <> terminal_item.product_id
                            OR terminal_ledger.sellable_delta <> terminal_inspection.sellable_quantity
                            OR terminal_ledger.damaged_delta <> terminal_inspection.damaged_quantity)))
                {$refundRelease})
            BEGIN SELECT RAISE(ABORT, 'coupon_usages invalid lifecycle transition'); END");
        DB::statement("CREATE TRIGGER coupon_usages_no_delete BEFORE DELETE ON coupon_usages
            BEGIN SELECT RAISE(ABORT, 'coupon_usages cannot be deleted'); END");
    }

    private function assertSupportedDatabase(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('VNPay refund processing supports only SQLite, MariaDB and MySQL.');
        }
    }
};
