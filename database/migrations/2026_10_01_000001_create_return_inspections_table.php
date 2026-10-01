<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TRIGGERS = [
        'return_inspections_insert_guard',
        'return_inspections_update_guard',
        'return_inspections_delete_guard',
    ];

    public function up(): void
    {
        $this->assertSupportedDatabase();
        $this->assertDependenciesExist();

        if (Schema::hasTable('return_inspections')
            || Schema::hasColumn('inventory_transactions', 'return_inspection_id')
            || collect(self::TRIGGERS)->contains(fn (string $trigger): bool => $this->triggerExists($trigger))) {
            throw new RuntimeException('Return Inspection migration found a partial migration state. Inspect the table, inventory source column and triggers manually; no automatic cleanup is performed.');
        }

        try {
            DB::getDriverName() === 'sqlite' ? $this->createSqliteTable() : $this->createMariaDbOrMySqlTable();
            $this->addInventorySource();
            $this->createInspectionTriggers();
        } catch (Throwable $exception) {
            throw new RuntimeException('Return Inspection migration stopped in a partial state. Inspect the table, inventory source column and triggers manually; no automatic cleanup is performed.', 0, $exception);
        }
    }

    public function down(): void
    {
        $this->assertSupportedDatabase();

        if (! Schema::hasTable('return_inspections') && ! Schema::hasColumn('inventory_transactions', 'return_inspection_id')) {
            return;
        }
        if (! Schema::hasTable('return_inspections') || ! Schema::hasColumn('inventory_transactions', 'return_inspection_id')) {
            throw new RuntimeException('Return Inspection rollback found a partial migration state. Inspect it manually; no automatic cleanup is performed.');
        }
        if (DB::table('inventory_transactions')->whereNotNull('return_inspection_id')->exists()) {
            throw new RuntimeException('Cannot remove inventory_transactions.return_inspection_id while ledger rows reference Return Inspections.');
        }
        if (DB::table('return_inspections')->exists()) {
            throw new RuntimeException('Cannot remove Return Inspection while evidence rows exist. No inspection was deleted.');
        }

        foreach (self::TRIGGERS as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
        $this->removeInventorySource();
        Schema::drop('return_inspections');
    }

    private function createMariaDbOrMySqlTable(): void
    {
        Schema::create('return_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->dateTime('received_at', 6);
            $table->foreignId('inspected_by')->nullable()->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->dateTime('inspected_at', 6)->nullable();
            $table->unsignedInteger('sellable_quantity')->nullable();
            $table->unsignedInteger('damaged_quantity')->nullable();
            $table->string('note', 500)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index('received_by', 'return_inspections_received_by_index');
            $table->index('inspected_by', 'return_inspections_inspected_by_index');
            $table->index('inspected_at', 'return_inspections_inspected_at_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE return_inspections
                ADD CONSTRAINT return_inspections_shape_check CHECK (
                    (inspected_by IS NULL AND inspected_at IS NULL AND sellable_quantity IS NULL AND damaged_quantity IS NULL)
                    OR
                    (inspected_by IS NOT NULL AND inspected_at IS NOT NULL AND sellable_quantity IS NOT NULL AND damaged_quantity IS NOT NULL)
                ),
                ADD CONSTRAINT return_inspections_sellable_quantity_check CHECK (sellable_quantity IS NULL OR sellable_quantity >= 0),
                ADD CONSTRAINT return_inspections_damaged_quantity_check CHECK (damaged_quantity IS NULL OR damaged_quantity >= 0),
                ADD CONSTRAINT return_inspections_time_check CHECK (inspected_at IS NULL OR inspected_at >= received_at)
            SQL);
    }

    private function createSqliteTable(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE return_inspections (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                order_item_id INTEGER NOT NULL,
                received_by INTEGER NOT NULL,
                received_at DATETIME NOT NULL,
                inspected_by INTEGER NULL,
                inspected_at DATETIME NULL,
                sellable_quantity INTEGER NULL,
                damaged_quantity INTEGER NULL,
                note VARCHAR(500) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT return_inspections_order_item_id_foreign FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT return_inspections_received_by_foreign FOREIGN KEY (received_by) REFERENCES users(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT return_inspections_inspected_by_foreign FOREIGN KEY (inspected_by) REFERENCES users(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT return_inspections_order_item_unique UNIQUE (order_item_id),
                CONSTRAINT return_inspections_shape_check CHECK (
                    (inspected_by IS NULL AND inspected_at IS NULL AND sellable_quantity IS NULL AND damaged_quantity IS NULL)
                    OR
                    (inspected_by IS NOT NULL AND inspected_at IS NOT NULL AND sellable_quantity IS NOT NULL AND damaged_quantity IS NOT NULL)
                ),
                CONSTRAINT return_inspections_sellable_quantity_check CHECK (sellable_quantity IS NULL OR sellable_quantity >= 0),
                CONSTRAINT return_inspections_damaged_quantity_check CHECK (damaged_quantity IS NULL OR damaged_quantity >= 0),
                CONSTRAINT return_inspections_time_check CHECK (inspected_at IS NULL OR inspected_at >= received_at)
            )
            SQL);
        DB::statement('CREATE INDEX return_inspections_received_by_index ON return_inspections (received_by)');
        DB::statement('CREATE INDEX return_inspections_inspected_by_index ON return_inspections (inspected_by)');
        DB::statement('CREATE INDEX return_inspections_inspected_at_index ON return_inspections (inspected_at)');
    }

    private function addInventorySource(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS inventory_transactions_update_guard');

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE inventory_transactions ADD COLUMN return_inspection_id INTEGER NULL REFERENCES return_inspections(id) ON UPDATE RESTRICT ON DELETE RESTRICT');
            DB::statement('CREATE INDEX inventory_transactions_return_inspection_id_index ON inventory_transactions (return_inspection_id)');
        } else {
            Schema::table('inventory_transactions', function (Blueprint $table): void {
                $table->unsignedBigInteger('return_inspection_id')->nullable()->after('order_item_id');
                $table->index('return_inspection_id', 'inventory_transactions_return_inspection_id_index');
                $table->foreign('return_inspection_id', 'inventory_transactions_return_inspection_id_foreign')
                    ->references('id')->on('return_inspections')->restrictOnUpdate()->restrictOnDelete();
            });
        }

        $this->createInventoryUpdateGuard(includeReturnInspection: true);
    }

    private function removeInventorySource(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS inventory_transactions_update_guard');

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX inventory_transactions_return_inspection_id_index');
            DB::statement('ALTER TABLE inventory_transactions DROP COLUMN return_inspection_id');
        } else {
            DB::statement('ALTER TABLE inventory_transactions DROP FOREIGN KEY inventory_transactions_return_inspection_id_foreign');
            DB::statement('ALTER TABLE inventory_transactions DROP INDEX inventory_transactions_return_inspection_id_index');
            DB::statement('ALTER TABLE inventory_transactions DROP COLUMN return_inspection_id');
        }

        $this->createInventoryUpdateGuard(includeReturnInspection: false);
    }

    private function createInventoryUpdateGuard(bool $includeReturnInspection): void
    {
        $returnMaria = $includeReturnInspection ? ' AND NEW.return_inspection_id <=> OLD.return_inspection_id' : '';
        $returnSqlite = $includeReturnInspection ? ' AND NEW.return_inspection_id IS OLD.return_inspection_id' : '';

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER inventory_transactions_update_guard BEFORE UPDATE ON inventory_transactions
                FOR EACH ROW BEGIN
                    IF NOT (OLD.actor_id IS NOT NULL AND NEW.actor_id IS NULL
                        AND NEW.id <=> OLD.id AND NEW.product_id <=> OLD.product_id AND NEW.type <=> OLD.type
                        AND NEW.sellable_delta <=> OLD.sellable_delta AND NEW.damaged_delta <=> OLD.damaged_delta
                        AND NEW.source_key <=> OLD.source_key AND NEW.adjustment_request_id <=> OLD.adjustment_request_id
                        AND NEW.order_item_id <=> OLD.order_item_id{$returnMaria}
                        AND NEW.reason <=> OLD.reason AND NEW.created_at <=> OLD.created_at) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory transactions are immutable';
                    END IF;
                END");

            return;
        }

        DB::statement("CREATE TRIGGER inventory_transactions_update_guard BEFORE UPDATE ON inventory_transactions
            WHEN NOT (OLD.actor_id IS NOT NULL AND NEW.actor_id IS NULL
                AND NEW.id IS OLD.id AND NEW.product_id IS OLD.product_id AND NEW.type IS OLD.type
                AND NEW.sellable_delta IS OLD.sellable_delta AND NEW.damaged_delta IS OLD.damaged_delta
                AND NEW.source_key IS OLD.source_key AND NEW.adjustment_request_id IS OLD.adjustment_request_id
                AND NEW.order_item_id IS OLD.order_item_id{$returnSqlite}
                AND NEW.reason IS OLD.reason AND NEW.created_at IS OLD.created_at)
            BEGIN SELECT RAISE(ABORT, 'inventory transactions are immutable'); END");
    }

    private function createInspectionTriggers(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER return_inspections_insert_guard BEFORE INSERT ON return_inspections
                FOR EACH ROW BEGIN
                    IF NEW.inspected_by IS NOT NULL OR NEW.inspected_at IS NOT NULL
                        OR NEW.sellable_quantity IS NOT NULL OR NEW.damaged_quantity IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'return inspections must be inserted pending';
                    END IF;
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER return_inspections_update_guard BEFORE UPDATE ON return_inspections
                FOR EACH ROW BEGIN
                    DECLARE item_quantity INT UNSIGNED;
                    IF NOT (
                        OLD.inspected_by IS NULL AND OLD.inspected_at IS NULL
                        AND OLD.sellable_quantity IS NULL AND OLD.damaged_quantity IS NULL
                        AND NEW.inspected_by IS NOT NULL AND NEW.inspected_at IS NOT NULL
                        AND NEW.sellable_quantity IS NOT NULL AND NEW.damaged_quantity IS NOT NULL
                        AND NEW.id <=> OLD.id AND NEW.order_item_id <=> OLD.order_item_id
                        AND NEW.received_by <=> OLD.received_by AND NEW.received_at <=> OLD.received_at
                        AND NEW.created_at <=> OLD.created_at AND NEW.updated_at >= OLD.updated_at
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'return inspection permits only pending to completed transition';
                    END IF;
                    SELECT quantity INTO item_quantity FROM order_items WHERE id = NEW.order_item_id;
                    IF item_quantity IS NULL OR NEW.sellable_quantity + NEW.damaged_quantity <> item_quantity THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'return inspection quantities must match order item quantity';
                    END IF;
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER return_inspections_delete_guard BEFORE DELETE ON return_inspections
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'return inspections cannot be deleted'
                SQL);

            return;
        }

        DB::statement(<<<'SQL'
            CREATE TRIGGER return_inspections_insert_guard BEFORE INSERT ON return_inspections
            WHEN NEW.inspected_by IS NOT NULL OR NEW.inspected_at IS NOT NULL
                OR NEW.sellable_quantity IS NOT NULL OR NEW.damaged_quantity IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'return inspections must be inserted pending'); END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER return_inspections_update_guard BEFORE UPDATE ON return_inspections
            WHEN NOT (
                OLD.inspected_by IS NULL AND OLD.inspected_at IS NULL
                AND OLD.sellable_quantity IS NULL AND OLD.damaged_quantity IS NULL
                AND NEW.inspected_by IS NOT NULL AND NEW.inspected_at IS NOT NULL
                AND NEW.sellable_quantity IS NOT NULL AND NEW.damaged_quantity IS NOT NULL
                AND NEW.id IS OLD.id AND NEW.order_item_id IS OLD.order_item_id
                AND NEW.received_by IS OLD.received_by AND NEW.received_at IS OLD.received_at
                AND NEW.created_at IS OLD.created_at AND NEW.updated_at >= OLD.updated_at
                AND (SELECT quantity FROM order_items WHERE id = NEW.order_item_id)
                    = NEW.sellable_quantity + NEW.damaged_quantity
            )
            BEGIN SELECT RAISE(ABORT, 'return inspection permits only valid pending to completed transition'); END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER return_inspections_delete_guard BEFORE DELETE ON return_inspections
            BEGIN SELECT RAISE(ABORT, 'return inspections cannot be deleted'); END
            SQL);
    }

    private function triggerExists(string $trigger): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger)->exists();
        }

        return DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', $trigger)
            ->exists();
    }

    private function assertDependenciesExist(): void
    {
        foreach (['users', 'orders', 'order_items', 'inventory_transactions'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Return Inspection migration requires the {$table} table.");
            }
        }
    }

    private function assertSupportedDatabase(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Return Inspection migration supports only SQLite, MariaDB and MySQL.');
        }
    }
};
