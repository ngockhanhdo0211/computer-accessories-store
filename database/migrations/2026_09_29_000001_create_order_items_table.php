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

        if (Schema::hasTable('order_items') || Schema::hasColumn('inventory_transactions', 'order_item_id')) {
            throw new RuntimeException('Order Item migration found a partial migration state. Inspect order_items and inventory_transactions.order_item_id manually; no automatic cleanup is performed.');
        }

        try {
            if (DB::getDriverName() === 'sqlite') {
                $this->createSqliteTable();
            } else {
                $this->createMariaDbOrMySqlTable();
            }

            $this->createItemImmutableTriggers();
            $this->addInventorySource();
        } catch (Throwable $exception) {
            throw new RuntimeException('Order Item migration stopped while creating order_items, its immutable triggers or the inventory ledger link. Inspect every partial structure manually; no automatic cleanup is performed.', 0, $exception);
        }
    }

    public function down(): void
    {
        $this->assertSupportedDatabase();

        foreach (['return_inspections', 'reviews'] as $dependent) {
            if (Schema::hasTable($dependent)) {
                throw new RuntimeException("Drop {$dependent} before rolling back order_items.");
            }
        }

        if (Schema::hasTable('order_items')) {
            DB::statement('DROP TRIGGER IF EXISTS order_items_update_guard');
            DB::statement('DROP TRIGGER IF EXISTS order_items_delete_guard');
        }

        if (Schema::hasColumn('inventory_transactions', 'order_item_id')) {
            if (DB::table('inventory_transactions')->whereNotNull('order_item_id')->exists()) {
                throw new RuntimeException('Cannot remove inventory_transactions.order_item_id while ledger rows reference Order Items.');
            }

            $this->removeInventorySource();
        }

        Schema::dropIfExists('order_items');
    }

    private function createItemImmutableTriggers(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_items_update_guard BEFORE UPDATE ON order_items
                    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order items are immutable'
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_items_delete_guard BEFORE DELETE ON order_items
                    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order items are immutable'
                SQL);

            return;
        }

        DB::statement(<<<'SQL'
            CREATE TRIGGER order_items_update_guard BEFORE UPDATE ON order_items
                BEGIN SELECT RAISE(ABORT, 'order items are immutable'); END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER order_items_delete_guard BEFORE DELETE ON order_items
                BEGIN SELECT RAISE(ABORT, 'order items are immutable'); END
            SQL);
    }

    private function createMariaDbOrMySqlTable(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnUpdate()->restrictOnDelete();
            $table->string('product_name');
            $table->string('sku', 80);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_vnd');
            $table->unsignedBigInteger('line_subtotal_vnd');
            $table->unsignedBigInteger('discount_vnd')->default(0);
            $table->unsignedBigInteger('line_total_vnd');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['order_id', 'product_id'], 'order_items_order_product_unique');
            $table->index(['product_id', 'order_id'], 'order_items_product_order_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE order_items
                ADD CONSTRAINT order_items_quantity_check CHECK (quantity > 0),
                ADD CONSTRAINT order_items_unit_price_check CHECK (unit_price_vnd >= 0),
                ADD CONSTRAINT order_items_subtotal_check CHECK (
                    line_subtotal_vnd >= 0 AND line_subtotal_vnd = unit_price_vnd * quantity
                ),
                ADD CONSTRAINT order_items_discount_check CHECK (
                    discount_vnd >= 0 AND discount_vnd <= line_subtotal_vnd
                ),
                ADD CONSTRAINT order_items_total_check CHECK (
                    line_total_vnd >= 0 AND line_total_vnd = line_subtotal_vnd - discount_vnd
                )
            SQL);
    }

    private function createSqliteTable(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE order_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                order_id INTEGER NOT NULL,
                product_id INTEGER NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                sku VARCHAR(80) NOT NULL,
                quantity INTEGER NOT NULL,
                unit_price_vnd INTEGER NOT NULL,
                line_subtotal_vnd INTEGER NOT NULL,
                discount_vnd INTEGER NOT NULL DEFAULT 0,
                line_total_vnd INTEGER NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT order_items_order_id_foreign FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT order_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES products(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT order_items_order_product_unique UNIQUE (order_id, product_id),
                CONSTRAINT order_items_quantity_check CHECK (quantity > 0),
                CONSTRAINT order_items_unit_price_check CHECK (unit_price_vnd >= 0),
                CONSTRAINT order_items_subtotal_check CHECK (
                    line_subtotal_vnd >= 0 AND line_subtotal_vnd = unit_price_vnd * quantity
                ),
                CONSTRAINT order_items_discount_check CHECK (
                    discount_vnd >= 0 AND discount_vnd <= line_subtotal_vnd
                ),
                CONSTRAINT order_items_total_check CHECK (
                    line_total_vnd >= 0 AND line_total_vnd = line_subtotal_vnd - discount_vnd
                )
            )
            SQL);

        DB::statement('CREATE INDEX order_items_product_order_index ON order_items (product_id, order_id)');
    }

    private function addInventorySource(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS inventory_transactions_update_guard');

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE inventory_transactions ADD COLUMN order_item_id INTEGER NULL REFERENCES order_items(id) ON UPDATE RESTRICT ON DELETE RESTRICT');
            DB::statement('CREATE INDEX inventory_transactions_order_item_id_index ON inventory_transactions (order_item_id)');
        } else {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('order_item_id')
                    ->nullable()
                    ->after('adjustment_request_id');
                $table->index('order_item_id', 'inventory_transactions_order_item_id_index');
                $table->foreign('order_item_id', 'inventory_transactions_order_item_id_foreign')
                    ->references('id')
                    ->on('order_items')
                    ->restrictOnUpdate()
                    ->restrictOnDelete();
            });
        }

        $this->createInventoryUpdateGuard(includeOrderItem: true);
    }

    private function removeInventorySource(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS inventory_transactions_update_guard');

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX inventory_transactions_order_item_id_index');
            DB::statement('ALTER TABLE inventory_transactions DROP COLUMN order_item_id');
        } else {
            DB::statement('ALTER TABLE inventory_transactions DROP FOREIGN KEY inventory_transactions_order_item_id_foreign');

            $indexes = collect(DB::select(
                'SHOW INDEX FROM inventory_transactions WHERE Column_name = ?',
                ['order_item_id'],
            ))->pluck('Key_name')->unique();

            foreach ($indexes as $index) {
                DB::statement('ALTER TABLE inventory_transactions DROP INDEX `'.$index.'`');
            }

            DB::statement('ALTER TABLE inventory_transactions DROP COLUMN order_item_id');
        }

        $this->createInventoryUpdateGuard(includeOrderItem: false);
    }

    private function createInventoryUpdateGuard(bool $includeOrderItem): void
    {
        $orderItemMaria = $includeOrderItem ? ' AND NEW.order_item_id <=> OLD.order_item_id' : '';
        $orderItemSqlite = $includeOrderItem ? ' AND NEW.order_item_id IS OLD.order_item_id' : '';

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER inventory_transactions_update_guard BEFORE UPDATE ON inventory_transactions
                FOR EACH ROW BEGIN
                    IF NOT (OLD.actor_id IS NOT NULL AND NEW.actor_id IS NULL
                        AND NEW.id <=> OLD.id AND NEW.product_id <=> OLD.product_id AND NEW.type <=> OLD.type
                        AND NEW.sellable_delta <=> OLD.sellable_delta AND NEW.damaged_delta <=> OLD.damaged_delta
                        AND NEW.source_key <=> OLD.source_key AND NEW.adjustment_request_id <=> OLD.adjustment_request_id
                        {$orderItemMaria}
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
                {$orderItemSqlite}
                AND NEW.reason IS OLD.reason AND NEW.created_at IS OLD.created_at)
            BEGIN SELECT RAISE(ABORT, 'inventory transactions are immutable'); END");
    }

    private function assertDependenciesExist(): void
    {
        foreach (['orders', 'products', 'inventory_transactions'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Order Item migration requires the {$table} table.");
            }
        }
    }

    private function assertSupportedDatabase(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Order Item migration supports only SQLite, MariaDB and MySQL.');
        }
    }
};
