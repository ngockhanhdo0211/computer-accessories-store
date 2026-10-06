<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = ['receive_event_key', 'receive_fingerprint', 'complete_event_key', 'complete_fingerprint'];

    public function up(): void
    {
        $this->assertSupported();
        $present = array_filter(self::COLUMNS, fn (string $column): bool => Schema::hasColumn('return_inspections', $column));
        if ($present !== []) {
            throw new RuntimeException('Return Inspection idempotency migration found partial or previously applied state.');
        }

        Schema::table('return_inspections', function (Blueprint $table): void {
            $table->char('receive_event_key', 36)->nullable();
            $table->char('receive_fingerprint', 64)->nullable();
            $table->char('complete_event_key', 36)->nullable();
            $table->char('complete_fingerprint', 64)->nullable();
            $table->unique('receive_event_key', 'return_inspections_receive_event_unique');
            $table->unique('complete_event_key', 'return_inspections_complete_event_unique');
        });
        $this->addChecks();
        $this->replaceLifecycleTriggers(true);
    }

    public function down(): void
    {
        $this->assertSupported();
        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('return_inspections', $column)) {
                throw new RuntimeException('Cannot roll back partial Return Inspection idempotency metadata.');
            }
        }
        if (DB::table('return_inspections')->whereNotNull('receive_event_key')->orWhereNotNull('complete_event_key')->exists()) {
            throw new RuntimeException('Cannot remove Return Inspection idempotency evidence.');
        }
        $this->replaceLifecycleTriggers(false);
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE return_inspections DROP CONSTRAINT return_inspections_receive_event_pair_check, DROP CONSTRAINT return_inspections_complete_event_pair_check, DROP CONSTRAINT return_inspections_receive_event_format_check, DROP CONSTRAINT return_inspections_complete_event_format_check, DROP CONSTRAINT return_inspections_receive_fingerprint_check, DROP CONSTRAINT return_inspections_complete_fingerprint_check');
        }
        Schema::table('return_inspections', function (Blueprint $table): void {
            $table->dropUnique('return_inspections_receive_event_unique');
            $table->dropUnique('return_inspections_complete_event_unique');
            $table->dropColumn(self::COLUMNS);
        });
    }

    private function addChecks(): void
    {
        $uuid = '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$';
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        $version = strtolower((string) DB::selectOne('SELECT VERSION() AS version')->version);
        $regex = fn (string $column, string $pattern): string => str_contains($version, 'mariadb')
            ? "{$column} REGEXP BINARY '{$pattern}'"
            : "REGEXP_LIKE({$column}, '{$pattern}', 'c')";
        DB::statement('ALTER TABLE return_inspections '
            .'ADD CONSTRAINT return_inspections_receive_event_pair_check CHECK ((receive_event_key IS NULL) = (receive_fingerprint IS NULL)), '
            .'ADD CONSTRAINT return_inspections_complete_event_pair_check CHECK ((complete_event_key IS NULL) = (complete_fingerprint IS NULL)), '
            ."ADD CONSTRAINT return_inspections_receive_event_format_check CHECK (receive_event_key IS NULL OR {$regex('receive_event_key', $uuid)}), "
            ."ADD CONSTRAINT return_inspections_complete_event_format_check CHECK (complete_event_key IS NULL OR {$regex('complete_event_key', $uuid)}), "
            ."ADD CONSTRAINT return_inspections_receive_fingerprint_check CHECK (receive_fingerprint IS NULL OR {$regex('receive_fingerprint', '^[0-9a-f]{64}$')}), "
            ."ADD CONSTRAINT return_inspections_complete_fingerprint_check CHECK (complete_fingerprint IS NULL OR {$regex('complete_fingerprint', '^[0-9a-f]{64}$')})");
    }

    private function replaceLifecycleTriggers(bool $withMetadata): void
    {
        foreach (['return_inspections_insert_guard', 'return_inspections_update_guard', 'return_inspections_delete_guard'] as $trigger) {
            DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
        }
        $receiveInsert = $withMetadata ? ' OR ((NEW.receive_event_key IS NULL) <> (NEW.receive_fingerprint IS NULL)) OR NEW.complete_event_key IS NOT NULL OR NEW.complete_fingerprint IS NOT NULL' : '';
        $receiveSameMysql = $withMetadata ? ' AND NEW.receive_event_key <=> OLD.receive_event_key AND NEW.receive_fingerprint <=> OLD.receive_fingerprint' : '';
        $completeMysql = $withMetadata ? ' AND NEW.complete_event_key IS NOT NULL AND NEW.complete_fingerprint IS NOT NULL' : '';
        $receiveSameSqlite = $withMetadata ? ' AND NEW.receive_event_key IS OLD.receive_event_key AND NEW.receive_fingerprint IS OLD.receive_fingerprint' : '';
        $completeSqlite = $withMetadata ? ' AND NEW.complete_event_key IS NOT NULL AND NEW.complete_fingerprint IS NOT NULL'.$this->sqliteMetadataFormatGuard('NEW.complete_event_key', 'NEW.complete_fingerprint') : '';
        $receiveFormatSqlite = $withMetadata ? $this->sqliteNullableMetadataFormatRejection('NEW.receive_event_key', 'NEW.receive_fingerprint') : '';
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER return_inspections_insert_guard BEFORE INSERT ON return_inspections FOR EACH ROW BEGIN IF NEW.inspected_by IS NOT NULL OR NEW.inspected_at IS NOT NULL OR NEW.sellable_quantity IS NOT NULL OR NEW.damaged_quantity IS NOT NULL{$receiveInsert} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'return inspections must be inserted pending'; END IF; END");
            DB::unprepared("CREATE TRIGGER return_inspections_update_guard BEFORE UPDATE ON return_inspections FOR EACH ROW BEGIN DECLARE item_quantity INT UNSIGNED; IF NOT (OLD.inspected_by IS NULL AND OLD.inspected_at IS NULL AND OLD.sellable_quantity IS NULL AND OLD.damaged_quantity IS NULL AND NEW.inspected_by IS NOT NULL AND NEW.inspected_at IS NOT NULL AND NEW.sellable_quantity IS NOT NULL AND NEW.damaged_quantity IS NOT NULL AND NEW.id <=> OLD.id AND NEW.order_item_id <=> OLD.order_item_id AND NEW.received_by <=> OLD.received_by AND NEW.received_at <=> OLD.received_at{$receiveSameMysql}{$completeMysql} AND NEW.created_at <=> OLD.created_at AND NEW.updated_at >= OLD.updated_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'return inspection permits only pending to completed transition'; END IF; SELECT quantity INTO item_quantity FROM order_items WHERE id = NEW.order_item_id; IF item_quantity IS NULL OR NEW.sellable_quantity + NEW.damaged_quantity <> item_quantity THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'return inspection quantities must match order item quantity'; END IF; END");
            DB::unprepared("CREATE TRIGGER return_inspections_delete_guard BEFORE DELETE ON return_inspections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'return inspections cannot be deleted'");

            return;
        }
        DB::statement("CREATE TRIGGER return_inspections_insert_guard BEFORE INSERT ON return_inspections WHEN NEW.inspected_by IS NOT NULL OR NEW.inspected_at IS NOT NULL OR NEW.sellable_quantity IS NOT NULL OR NEW.damaged_quantity IS NOT NULL{$receiveInsert}{$receiveFormatSqlite} BEGIN SELECT RAISE(ABORT, 'return inspections must be inserted pending'); END");
        DB::statement("CREATE TRIGGER return_inspections_update_guard BEFORE UPDATE ON return_inspections WHEN NOT (OLD.inspected_by IS NULL AND OLD.inspected_at IS NULL AND OLD.sellable_quantity IS NULL AND OLD.damaged_quantity IS NULL AND NEW.inspected_by IS NOT NULL AND NEW.inspected_at IS NOT NULL AND NEW.sellable_quantity IS NOT NULL AND NEW.damaged_quantity IS NOT NULL AND NEW.id IS OLD.id AND NEW.order_item_id IS OLD.order_item_id AND NEW.received_by IS OLD.received_by AND NEW.received_at IS OLD.received_at{$receiveSameSqlite}{$completeSqlite} AND NEW.created_at IS OLD.created_at AND NEW.updated_at >= OLD.updated_at AND (SELECT quantity FROM order_items WHERE id = NEW.order_item_id) = NEW.sellable_quantity + NEW.damaged_quantity) BEGIN SELECT RAISE(ABORT, 'return inspection permits only valid pending to completed transition'); END");
        DB::statement("CREATE TRIGGER return_inspections_delete_guard BEFORE DELETE ON return_inspections BEGIN SELECT RAISE(ABORT, 'return inspections cannot be deleted'); END");
    }

    private function sqliteMetadataFormatGuard(string $key, string $fingerprint): string
    {
        return " AND LENGTH({$key}) = 36 AND {$key} = LOWER({$key})"
            ." AND SUBSTR({$key}, 9, 1) = '-' AND SUBSTR({$key}, 14, 1) = '-' AND SUBSTR({$key}, 19, 1) = '-' AND SUBSTR({$key}, 24, 1) = '-'"
            ." AND SUBSTR({$key}, 15, 1) GLOB '[1-5]' AND SUBSTR({$key}, 20, 1) GLOB '[89ab]'"
            ." AND REPLACE({$key}, '-', '') NOT GLOB '*[^0-9a-f]*'"
            ." AND LENGTH({$fingerprint}) = 64 AND {$fingerprint} = LOWER({$fingerprint}) AND {$fingerprint} NOT GLOB '*[^0-9a-f]*'";
    }

    private function sqliteNullableMetadataFormatRejection(string $key, string $fingerprint): string
    {
        $valid = ltrim($this->sqliteMetadataFormatGuard($key, $fingerprint), ' AND');

        return " OR ({$key} IS NOT NULL AND NOT ({$valid}))";
    }

    private function assertSupported(): void
    {
        if (! Schema::hasTable('return_inspections') || ! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Return Inspection idempotency migration requires SQLite, MariaDB or MySQL and its foundation table.');
        }
    }
};
