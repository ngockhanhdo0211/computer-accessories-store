<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['orders', 'users', 'inventory_transactions', 'refunds', 'coupon_usages', 'order_items', 'return_inspections'] as $dependency) {
            if (! Schema::hasTable($dependency)) {
                throw new RuntimeException("Customer cancellation migration requires the {$dependency} table.");
            }
        }
        if (Schema::hasTable('order_cancellation_requests') || Schema::hasColumn('inventory_transactions', 'order_cancellation_request_id')) {
            throw new RuntimeException('Customer cancellation migration found a partial schema state.');
        }
        Schema::create('order_cancellation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique('order_cancellation_requests_order_unique')->constrained('orders')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->uuid('request_key');
            $table->uuid('review_event_key')->nullable()->unique('order_cancellation_requests_review_event_unique');
            $table->char('review_fingerprint', 64)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['customer_id', 'request_key'], 'order_cancellation_requests_customer_key_unique');
            $table->index(['status', 'created_at'], 'order_cancellation_requests_status_created_index');
        });
        DB::statement('DROP TRIGGER IF EXISTS coupon_usages_lifecycle_update');
        if (DB::getDriverName() === 'sqlite') {
            // SQLite can add this nullable FK without rebuilding the immutable ledger
            // table. A Schema table rebuild would silently discard its guard triggers.
            DB::statement('ALTER TABLE inventory_transactions ADD COLUMN order_cancellation_request_id INTEGER NULL REFERENCES order_cancellation_requests(id) ON UPDATE RESTRICT ON DELETE RESTRICT');
            DB::statement('CREATE INDEX inventory_transactions_order_cancellation_request_id_index ON inventory_transactions (order_cancellation_request_id)');
        } else {
            Schema::table('inventory_transactions', function (Blueprint $table): void {
                $table->foreignId('order_cancellation_request_id')->nullable()->after('return_inspection_id')
                    ->constrained('order_cancellation_requests', 'id', 'inventory_transactions_cancellation_request_foreign')
                    ->restrictOnUpdate()->restrictOnDelete();
            });
        }
        $this->createRequestGuards();
        $this->createInventoryCancellationSourceUpdateGuard();
        $this->replaceInventoryGuard(true);
        $this->replaceCouponTrigger(true);
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_domain_check');
            DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_domain_check CHECK (amount_vnd > 0 AND BINARY reason IN ('stock_unavailable','coupon_capacity_unavailable','snapshot_incomplete','customer_cancellation') AND BINARY status IN ('pending','succeeded','failed'))");
        } else {
            $this->replaceRefundInsertGuard(true);
        }
    }

    public function down(): void
    {
        if (DB::table('order_cancellation_requests')->exists() || DB::table('inventory_transactions')->whereNotNull('order_cancellation_request_id')->exists()) {
            throw new RuntimeException('Cannot rollback Customer cancellation evidence.');
        }
        DB::statement('DROP TRIGGER IF EXISTS coupon_usages_lifecycle_update');
        $this->replaceInventoryGuard(false);
        DB::statement('DROP TRIGGER IF EXISTS order_cancellation_requests_insert_guard');
        DB::statement('DROP TRIGGER IF EXISTS order_cancellation_requests_update_guard');
        DB::statement('DROP TRIGGER IF EXISTS order_cancellation_requests_delete_guard');
        DB::statement('DROP TRIGGER IF EXISTS inventory_transactions_cancellation_source_update_guard');
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_domain_check');
            DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_domain_check CHECK (amount_vnd > 0 AND BINARY reason IN ('stock_unavailable','coupon_capacity_unavailable','snapshot_incomplete') AND BINARY status IN ('pending','succeeded','failed'))");
        } else {
            $this->replaceRefundInsertGuard(false);
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX inventory_transactions_order_cancellation_request_id_index');
            DB::statement('ALTER TABLE inventory_transactions DROP COLUMN order_cancellation_request_id');
        } else {
            Schema::table('inventory_transactions', function (Blueprint $table): void {
                $table->dropForeign('inventory_transactions_cancellation_request_foreign');
                $table->dropColumn('order_cancellation_request_id');
            });
        }
        Schema::drop('order_cancellation_requests');
        $this->replaceCouponTrigger(false);
    }

    private function createRequestGuards(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $version = strtolower((string) DB::selectOne('SELECT VERSION() AS version')->version);
            $fingerprintCheck = str_contains($version, 'mariadb')
                ? "review_fingerprint REGEXP BINARY '^[0-9a-f]{64}$'"
                : "REGEXP_LIKE(review_fingerprint, '^[0-9a-f]{64}$', 'c')";
            DB::statement("ALTER TABLE order_cancellation_requests ADD CONSTRAINT order_cancellation_requests_shape_check CHECK (
                TRIM(reason) <> '' AND BINARY status IN ('pending','approved','rejected')
                AND ((BINARY status = 'pending' AND reviewed_by IS NULL AND review_note IS NULL AND reviewed_at IS NULL AND review_event_key IS NULL AND review_fingerprint IS NULL)
                  OR (BINARY status = 'approved' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND review_event_key IS NOT NULL AND {$fingerprintCheck})
                  OR (BINARY status = 'rejected' AND reviewed_by IS NOT NULL AND review_note IS NOT NULL AND TRIM(review_note) <> '' AND reviewed_at IS NOT NULL AND review_event_key IS NOT NULL AND {$fingerprintCheck})))");
            DB::unprepared("CREATE TRIGGER order_cancellation_requests_insert_guard BEFORE INSERT ON order_cancellation_requests FOR EACH ROW BEGIN
                IF NOT EXISTS (SELECT 1 FROM orders o WHERE o.id = NEW.order_id AND o.user_id = NEW.customer_id AND BINARY o.status = 'da_dat') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cancellation request must belong to a placed customer order'; END IF;
            END");
            DB::unprepared("CREATE TRIGGER order_cancellation_requests_update_guard BEFORE UPDATE ON order_cancellation_requests FOR EACH ROW BEGIN
                IF NOT (NEW.order_id <=> OLD.order_id AND NEW.customer_id <=> OLD.customer_id AND BINARY NEW.reason <=> BINARY OLD.reason AND NEW.request_key <=> OLD.request_key AND NEW.created_at <=> OLD.created_at AND BINARY OLD.status = 'pending' AND BINARY NEW.status IN ('approved','rejected')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cancellation request lifecycle is immutable'; END IF;
            END");
            DB::unprepared("CREATE TRIGGER order_cancellation_requests_delete_guard BEFORE DELETE ON order_cancellation_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cancellation requests cannot be deleted'");

            return;
        }
        DB::statement("CREATE TRIGGER order_cancellation_requests_insert_guard BEFORE INSERT ON order_cancellation_requests WHEN TRIM(NEW.reason) = '' OR NEW.status <> 'pending' OR NEW.reviewed_by IS NOT NULL OR NEW.review_note IS NOT NULL OR NEW.reviewed_at IS NOT NULL OR NEW.review_event_key IS NOT NULL OR NEW.review_fingerprint IS NOT NULL OR NOT EXISTS (SELECT 1 FROM orders o WHERE o.id = NEW.order_id AND o.user_id = NEW.customer_id AND o.status = 'da_dat') BEGIN SELECT RAISE(ABORT, 'invalid cancellation request'); END");
        DB::statement("CREATE TRIGGER order_cancellation_requests_update_guard BEFORE UPDATE ON order_cancellation_requests WHEN NOT (NEW.order_id IS OLD.order_id AND NEW.customer_id IS OLD.customer_id AND NEW.reason IS OLD.reason AND NEW.request_key IS OLD.request_key AND NEW.created_at IS OLD.created_at AND OLD.status = 'pending' AND ((NEW.status = 'approved' AND NEW.reviewed_by IS NOT NULL AND NEW.reviewed_at IS NOT NULL AND NEW.review_event_key IS NOT NULL AND LENGTH(NEW.review_fingerprint) = 64 AND NEW.review_fingerprint NOT GLOB '*[^0-9a-f]*') OR (NEW.status = 'rejected' AND NEW.reviewed_by IS NOT NULL AND NEW.review_note IS NOT NULL AND TRIM(NEW.review_note) <> '' AND NEW.reviewed_at IS NOT NULL AND NEW.review_event_key IS NOT NULL AND LENGTH(NEW.review_fingerprint) = 64 AND NEW.review_fingerprint NOT GLOB '*[^0-9a-f]*'))) BEGIN SELECT RAISE(ABORT, 'cancellation request lifecycle is immutable'); END");
        DB::statement("CREATE TRIGGER order_cancellation_requests_delete_guard BEFORE DELETE ON order_cancellation_requests BEGIN SELECT RAISE(ABORT, 'cancellation requests cannot be deleted'); END");
    }

    private function replaceInventoryGuard(bool $allowRequest): void
    {
        DB::statement('DROP TRIGGER IF EXISTS inventory_transactions_terminal_insert_check');
        $requestBranch = $allowRequest ? " OR (NEW.return_inspection_id IS NULL AND NEW.order_cancellation_request_id IS NOT NULL
            AND NEW.sellable_delta = terminal_item.quantity AND NEW.damaged_delta = 0
            AND EXISTS (SELECT 1 FROM order_cancellation_requests cancellation_request WHERE cancellation_request.id = NEW.order_cancellation_request_id AND cancellation_request.order_id = terminal_item.order_id AND cancellation_request.status = 'approved'))" : '';
        $condition = 'NEW.order_item_id IS NOT NULL AND NEW.sellable_delta >= 0 AND NEW.damaged_delta >= 0 AND EXISTS (
            SELECT 1 FROM order_items terminal_item WHERE terminal_item.id = NEW.order_item_id AND terminal_item.product_id = NEW.product_id AND (
                (NEW.return_inspection_id IS NOT NULL '.($allowRequest ? 'AND NEW.order_cancellation_request_id IS NULL ' : '')."AND EXISTS (SELECT 1 FROM return_inspections terminal_inspection WHERE terminal_inspection.id = NEW.return_inspection_id AND terminal_inspection.order_item_id = terminal_item.id AND terminal_inspection.inspected_by IS NOT NULL AND terminal_inspection.inspected_at IS NOT NULL AND terminal_inspection.sellable_quantity = NEW.sellable_delta AND terminal_inspection.damaged_quantity = NEW.damaged_delta AND terminal_inspection.sellable_quantity + terminal_inspection.damaged_quantity = terminal_item.quantity)) {$requestBranch}
            ))";
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER inventory_transactions_terminal_insert_check BEFORE INSERT ON inventory_transactions FOR EACH ROW BEGIN IF BINARY NEW.type = 'cancel_restore' AND NOT ({$condition}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cancel_restore source evidence is invalid'; END IF; END");
        } else {
            DB::statement("CREATE TRIGGER inventory_transactions_terminal_insert_check BEFORE INSERT ON inventory_transactions WHEN NEW.type = 'cancel_restore' AND NOT ({$condition}) BEGIN SELECT RAISE(ABORT, 'cancel_restore source evidence is invalid'); END");
        }
    }

    private function createInventoryCancellationSourceUpdateGuard(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER inventory_transactions_cancellation_source_update_guard BEFORE UPDATE ON inventory_transactions FOR EACH ROW BEGIN IF NOT (NEW.order_cancellation_request_id <=> OLD.order_cancellation_request_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory cancellation source is immutable'; END IF; END");
        } else {
            DB::statement("CREATE TRIGGER inventory_transactions_cancellation_source_update_guard BEFORE UPDATE ON inventory_transactions WHEN NEW.order_cancellation_request_id IS NOT OLD.order_cancellation_request_id BEGIN SELECT RAISE(ABORT, 'inventory cancellation source is immutable'); END");
        }
    }

    private function replaceCouponTrigger(bool $allowRequest): void
    {
        DB::statement('DROP TRIGGER IF EXISTS coupon_usages_lifecycle_update');
        $customerRelease = $allowRequest ? " OR (OLD.status = 'consumed' AND NEW.status = 'released' AND OLD.payment_attempt_id IS NULL AND NEW.payment_attempt_id IS NULL AND NEW.order_id = OLD.order_id AND NEW.consumed_at = OLD.consumed_at AND NEW.released_at IS NOT NULL AND NEW.late_callback_exception = 0
            AND EXISTS (SELECT 1 FROM orders o JOIN order_cancellation_requests r ON r.order_id = o.id WHERE o.id = NEW.order_id AND o.user_id = NEW.customer_id AND o.coupon_id = NEW.coupon_id AND o.payment_method = 'cod' AND o.payment_status = 'chua_thanh_toan' AND o.status = 'da_huy' AND r.status = 'approved')
            AND EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = NEW.order_id)
            AND NOT EXISTS (SELECT 1 FROM order_items i LEFT JOIN inventory_transactions l ON l.order_item_id = i.id AND l.type = 'cancel_restore' LEFT JOIN order_cancellation_requests r ON r.id = l.order_cancellation_request_id WHERE i.order_id = NEW.order_id AND (l.id IS NULL OR r.order_id <> NEW.order_id OR l.sellable_delta <> i.quantity OR l.damaged_delta <> 0)))" : '';
        $codInspection = "(OLD.status = 'consumed' AND NEW.status = 'released' AND OLD.payment_attempt_id IS NULL AND NEW.payment_attempt_id IS NULL AND NEW.order_id = OLD.order_id AND NEW.consumed_at = OLD.consumed_at AND NEW.released_at IS NOT NULL AND NEW.late_callback_exception = 0
            AND EXISTS (SELECT 1 FROM orders terminal_order WHERE terminal_order.id = NEW.order_id AND terminal_order.user_id = NEW.customer_id AND terminal_order.coupon_id = NEW.coupon_id AND terminal_order.payment_method = 'cod' AND terminal_order.payment_status = 'chua_thanh_toan' AND terminal_order.status = 'da_huy' AND terminal_order.delivered_at IS NULL)
            AND EXISTS (SELECT 1 FROM order_items terminal_item WHERE terminal_item.order_id = NEW.order_id)
            AND NOT EXISTS (SELECT 1 FROM order_items terminal_item LEFT JOIN return_inspections terminal_inspection ON terminal_inspection.order_item_id = terminal_item.id LEFT JOIN inventory_transactions terminal_ledger ON terminal_ledger.order_item_id = terminal_item.id AND terminal_ledger.type = 'cancel_restore' WHERE terminal_item.order_id = NEW.order_id AND (terminal_inspection.id IS NULL OR terminal_inspection.inspected_by IS NULL OR terminal_inspection.inspected_at IS NULL OR terminal_ledger.id IS NULL OR terminal_ledger.return_inspection_id <> terminal_inspection.id OR terminal_ledger.product_id <> terminal_item.product_id OR terminal_ledger.sellable_delta <> terminal_inspection.sellable_quantity OR terminal_ledger.damaged_delta <> terminal_inspection.damaged_quantity)))";
        $refundRelease = "(OLD.status = 'consumed' AND NEW.status = 'released' AND NEW.payment_attempt_id = OLD.payment_attempt_id AND NEW.order_id = OLD.order_id AND NEW.consumed_at = OLD.consumed_at AND NEW.released_at IS NOT NULL AND EXISTS (SELECT 1 FROM refunds r JOIN refund_gateway_attempts g ON g.refund_id = r.id JOIN payment_attempts p ON p.id = r.payment_attempt_id LEFT JOIN orders o ON o.id = r.order_id WHERE r.payment_attempt_id = NEW.payment_attempt_id AND r.status = 'succeeded' AND g.status = 'succeeded' AND p.status = 'hoan_tien' AND p.user_id = NEW.customer_id AND (r.order_id IS NULL OR r.order_id = NEW.order_id) AND (o.id IS NULL OR (o.user_id = NEW.customer_id AND o.coupon_id = NEW.coupon_id))))";
        $allowed = "(OLD.status = 'reserved' AND NEW.status = 'released') OR (OLD.status = 'reserved' AND NEW.status = 'consumed' AND NEW.late_callback_exception = 0) OR (OLD.status = 'released' AND NEW.status = 'consumed' AND NEW.late_callback_exception = 1 AND NEW.released_at = OLD.released_at) OR {$codInspection} {$customerRelease} OR {$refundRelease}";
        if (DB::getDriverName() === 'mysql') {
            $allowed = str_replace(['OLD.payment_attempt_id IS NULL', 'NEW.payment_attempt_id IS NULL', 'NEW.order_id = OLD.order_id', 'NEW.consumed_at = OLD.consumed_at'], ['OLD.payment_attempt_id IS NULL', 'NEW.payment_attempt_id IS NULL', 'NEW.order_id <=> OLD.order_id', 'NEW.consumed_at <=> OLD.consumed_at'], $allowed);
            DB::unprepared("CREATE TRIGGER coupon_usages_lifecycle_update BEFORE UPDATE ON coupon_usages FOR EACH ROW BEGIN IF NOT (NEW.coupon_id <=> OLD.coupon_id AND NEW.customer_id <=> OLD.customer_id AND NEW.payment_attempt_id <=> OLD.payment_attempt_id AND NEW.reserved_at <=> OLD.reserved_at AND NEW.expires_at <=> OLD.expires_at AND NEW.created_at <=> OLD.created_at AND ({$allowed})) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'coupon_usages invalid lifecycle transition'; END IF; END");
        } else {
            DB::statement("CREATE TRIGGER coupon_usages_lifecycle_update BEFORE UPDATE ON coupon_usages WHEN NEW.coupon_id <> OLD.coupon_id OR NEW.customer_id <> OLD.customer_id OR COALESCE(NEW.payment_attempt_id,-1) <> COALESCE(OLD.payment_attempt_id,-1) OR COALESCE(NEW.reserved_at,'') <> COALESCE(OLD.reserved_at,'') OR COALESCE(NEW.expires_at,'') <> COALESCE(OLD.expires_at,'') OR NEW.created_at <> OLD.created_at OR NOT ({$allowed}) BEGIN SELECT RAISE(ABORT, 'coupon_usages invalid lifecycle transition'); END");
        }
    }

    private function replaceRefundInsertGuard(bool $allowCustomerCancellation): void
    {
        DB::statement('DROP TRIGGER IF EXISTS refunds_insert_check');
        $reasons = $allowCustomerCancellation
            ? "'stock_unavailable','coupon_capacity_unavailable','snapshot_incomplete','customer_cancellation'"
            : "'stock_unavailable','coupon_capacity_unavailable','snapshot_incomplete'";
        DB::unprepared("CREATE TRIGGER refunds_insert_check BEFORE INSERT ON refunds
            WHEN NOT (NEW.amount_vnd > 0 AND NEW.reason IN ({$reasons}) AND NEW.status IN ('pending','succeeded','failed'))
            BEGIN SELECT RAISE(ABORT, 'refund domain check failed'); END");
    }
};
