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
        foreach (['payment_attempts', 'orders'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("VNPay callback migration requires {$table}.");
            }
        }
        if (Schema::hasTable('refunds') || Schema::hasColumn('payment_attempts', 'callback_fingerprint')) {
            throw new RuntimeException('VNPay callback migration found a partial schema state. Inspect it manually.');
        }

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->string('gateway_transaction_status', 2)->nullable()->after('gateway_result_code');
            $table->dateTime('gateway_paid_at', 6)->nullable()->after('gateway_transaction_status');
            $table->string('gateway_bank_code', 40)->nullable()->after('gateway_paid_at');
            $table->char('callback_fingerprint', 64)->nullable()->after('gateway_bank_code');
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_attempt_id')->unique('refunds_attempt_unique')->constrained('payment_attempts')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('amount_vnd');
            $table->string('reason', 40);
            $table->string('status', 16)->default('pending');
            $table->string('gateway_refund_reference', 100)->nullable()->unique('refunds_gateway_reference_unique');
            $table->string('note', 500)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['status', 'created_at'], 'refunds_status_created_index');
        });

        $this->createChecksAndGuards();
    }

    public function down(): void
    {
        $this->assertSupportedDatabase();
        if ((Schema::hasTable('refunds') && DB::table('refunds')->exists())
            || (Schema::hasColumn('payment_attempts', 'callback_fingerprint')
                && DB::table('payment_attempts')->whereNotNull('callback_fingerprint')->exists())) {
            throw new RuntimeException('VNPay callback rollback requires no Refund or verified callback evidence. No data was deleted.');
        }

        DB::statement('DROP TRIGGER IF EXISTS refunds_delete_guard');
        DB::statement('DROP TRIGGER IF EXISTS refunds_insert_check');
        DB::statement('DROP TRIGGER IF EXISTS refunds_update_guard');
        DB::statement('DROP TRIGGER IF EXISTS payment_attempts_callback_insert_check');
        DB::statement('DROP TRIGGER IF EXISTS payment_attempts_callback_update_check');
        Schema::dropIfExists('refunds');

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_callback_evidence_check');
        }
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn(['gateway_transaction_status', 'gateway_paid_at', 'gateway_bank_code', 'callback_fingerprint']);
        });
    }

    private function createChecksAndGuards(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $attemptCheckMaria = "(
                (BINARY status = 'chua_thanh_toan' AND gateway_transaction_id IS NULL AND gateway_result_code IS NULL
                    AND gateway_transaction_status IS NULL AND gateway_paid_at IS NULL AND gateway_bank_code IS NULL
                    AND callback_fingerprint IS NULL AND verified_at IS NULL)
                OR (BINARY status IN ('da_thanh_toan','hoan_tien') AND BINARY gateway_result_code = '00'
                    AND BINARY gateway_transaction_status = '00' AND gateway_transaction_id IS NOT NULL
                    AND gateway_transaction_id <> '' AND gateway_transaction_id <> '0'
                    AND gateway_paid_at IS NOT NULL
                    AND callback_fingerprint IS NOT NULL
                    AND callback_fingerprint REGEXP BINARY '^[0-9a-f]{64}$' AND verified_at IS NOT NULL)
                OR (BINARY status = 'that_bai'
                    AND NOT (BINARY gateway_result_code = '00' AND BINARY gateway_transaction_status = '00')
                    AND gateway_result_code REGEXP BINARY '^[0-9]{2}$'
                    AND gateway_transaction_status REGEXP BINARY '^[0-9]{2}$'
                    AND gateway_transaction_id IS NULL
                    AND gateway_paid_at IS NOT NULL
                    AND callback_fingerprint IS NOT NULL
                    AND callback_fingerprint REGEXP BINARY '^[0-9a-f]{64}$' AND verified_at IS NOT NULL)
            )";
            DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_callback_evidence_check CHECK ({$attemptCheckMaria})");
            DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_domain_check CHECK (
                amount_vnd > 0
                AND BINARY reason IN ('stock_unavailable','coupon_capacity_unavailable','snapshot_incomplete')
                AND BINARY status IN ('pending','succeeded','failed')
            )");
            DB::unprepared("CREATE TRIGGER refunds_delete_guard BEFORE DELETE ON refunds
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refunds cannot be deleted'");
            DB::unprepared("CREATE TRIGGER refunds_update_guard BEFORE UPDATE ON refunds
                FOR EACH ROW BEGIN
                    IF NOT (NEW.payment_attempt_id <=> OLD.payment_attempt_id
                        AND NEW.order_id <=> OLD.order_id
                        AND NEW.amount_vnd <=> OLD.amount_vnd
                        AND BINARY NEW.reason <=> BINARY OLD.reason
                        AND NEW.created_at <=> OLD.created_at
                        AND (BINARY NEW.status = BINARY OLD.status
                            OR (BINARY OLD.status = 'pending' AND BINARY NEW.status IN ('succeeded','failed')))) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refund identity is immutable';
                    END IF;
                END");

            return;
        }

        $attemptCheckNew = "(
            (NEW.status = 'chua_thanh_toan' AND NEW.gateway_transaction_id IS NULL AND NEW.gateway_result_code IS NULL
                AND NEW.gateway_transaction_status IS NULL AND NEW.gateway_paid_at IS NULL AND NEW.gateway_bank_code IS NULL
                AND NEW.callback_fingerprint IS NULL AND NEW.verified_at IS NULL)
            OR (NEW.status IN ('da_thanh_toan','hoan_tien') AND NEW.gateway_result_code = '00'
                AND NEW.gateway_transaction_status = '00' AND NEW.gateway_transaction_id IS NOT NULL
                AND NEW.gateway_transaction_id <> '' AND NEW.gateway_transaction_id <> '0'
                AND NEW.gateway_paid_at IS NOT NULL
                AND NEW.callback_fingerprint IS NOT NULL AND LENGTH(NEW.callback_fingerprint) = 64
                AND NEW.callback_fingerprint NOT GLOB '*[^0-9a-f]*' AND NEW.verified_at IS NOT NULL)
            OR (NEW.status = 'that_bai' AND NOT (NEW.gateway_result_code = '00' AND NEW.gateway_transaction_status = '00')
                AND LENGTH(NEW.gateway_result_code) = 2 AND NEW.gateway_result_code NOT GLOB '*[^0-9]*'
                AND LENGTH(NEW.gateway_transaction_status) = 2 AND NEW.gateway_transaction_status NOT GLOB '*[^0-9]*'
                AND NEW.gateway_transaction_id IS NULL AND NEW.callback_fingerprint IS NOT NULL
                AND NEW.gateway_paid_at IS NOT NULL
                AND LENGTH(NEW.callback_fingerprint) = 64
                AND NEW.callback_fingerprint NOT GLOB '*[^0-9a-f]*' AND NEW.verified_at IS NOT NULL)
        )";
        DB::unprepared("CREATE TRIGGER payment_attempts_callback_insert_check BEFORE INSERT ON payment_attempts
            WHEN NOT {$attemptCheckNew} BEGIN SELECT RAISE(ABORT, 'payment attempt callback evidence check failed'); END");
        DB::unprepared("CREATE TRIGGER payment_attempts_callback_update_check BEFORE UPDATE ON payment_attempts
            WHEN NOT {$attemptCheckNew} BEGIN SELECT RAISE(ABORT, 'payment attempt callback evidence check failed'); END");
        DB::unprepared("CREATE TRIGGER refunds_insert_check BEFORE INSERT ON refunds
            WHEN NOT (NEW.amount_vnd > 0
                AND NEW.reason IN ('stock_unavailable','coupon_capacity_unavailable','snapshot_incomplete')
                AND NEW.status IN ('pending','succeeded','failed'))
            BEGIN SELECT RAISE(ABORT, 'refund domain check failed'); END");
        DB::unprepared("CREATE TRIGGER refunds_update_guard BEFORE UPDATE ON refunds
            WHEN NOT (NEW.payment_attempt_id IS OLD.payment_attempt_id AND NEW.order_id IS OLD.order_id
                AND NEW.amount_vnd IS OLD.amount_vnd AND NEW.reason IS OLD.reason
                AND NEW.created_at IS OLD.created_at
                AND (NEW.status = OLD.status OR (OLD.status = 'pending' AND NEW.status IN ('succeeded','failed'))))
            BEGIN SELECT RAISE(ABORT, 'refund identity is immutable'); END");
        DB::unprepared("CREATE TRIGGER refunds_delete_guard BEFORE DELETE ON refunds
            BEGIN SELECT RAISE(ABORT, 'refunds cannot be deleted'); END");
    }

    private function assertSupportedDatabase(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('VNPay callback migration supports only SQLite, MariaDB and MySQL.');
        }
    }
};
