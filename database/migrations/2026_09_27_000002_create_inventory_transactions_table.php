<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_transactions')) {
            throw new RuntimeException('Inventory migration is pending but inventory_transactions already exists. Inspect it manually; no automatic cleanup is performed.');
        }

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnUpdate()->restrictOnDelete();
            $table->string('type', 24);
            $table->integer('sellable_delta');
            $table->integer('damaged_delta');
            $table->string('source_key', 100);
            $table->foreignId('adjustment_request_id')->nullable()->unique()->constrained('inventory_adjustment_requests')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnUpdate()->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->dateTime('created_at', 6);

            $table->unique(['type', 'source_key', 'product_id'], 'inventory_transactions_source_unique');
            $table->index(['product_id', 'created_at', 'id'], 'inventory_transactions_product_created_index');
            $table->index('type');
            $table->index(['actor_id', 'created_at'], 'inventory_transactions_actor_created_index');
        });

        $types = "'import','sale','cancel_restore','damaged','manual_adjustment'";
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_transactions
                ADD CONSTRAINT inventory_transactions_type_check CHECK (BINARY type IN ({$types})),
                ADD CONSTRAINT inventory_transactions_delta_check CHECK (sellable_delta <> 0 OR damaged_delta <> 0)");
            DB::unprepared("CREATE TRIGGER inventory_transactions_update_guard BEFORE UPDATE ON inventory_transactions
                FOR EACH ROW BEGIN
                    IF NOT (OLD.actor_id IS NOT NULL AND NEW.actor_id IS NULL
                        AND NEW.id <=> OLD.id AND NEW.product_id <=> OLD.product_id AND NEW.type <=> OLD.type
                        AND NEW.sellable_delta <=> OLD.sellable_delta AND NEW.damaged_delta <=> OLD.damaged_delta
                        AND NEW.source_key <=> OLD.source_key AND NEW.adjustment_request_id <=> OLD.adjustment_request_id
                        AND NEW.reason <=> OLD.reason AND NEW.created_at <=> OLD.created_at) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory transactions are immutable';
                    END IF;
                END");
            DB::unprepared("CREATE TRIGGER inventory_transactions_delete_guard BEFORE DELETE ON inventory_transactions
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory transactions are immutable'");
        } else {
            DB::statement("CREATE TRIGGER inventory_transactions_insert_check BEFORE INSERT ON inventory_transactions
                WHEN NEW.type NOT IN ({$types}) OR (NEW.sellable_delta = 0 AND NEW.damaged_delta = 0)
                BEGIN SELECT RAISE(ABORT, 'inventory transaction check failed'); END");
            DB::statement("CREATE TRIGGER inventory_transactions_update_guard BEFORE UPDATE ON inventory_transactions
                WHEN NOT (OLD.actor_id IS NOT NULL AND NEW.actor_id IS NULL
                    AND NEW.id IS OLD.id AND NEW.product_id IS OLD.product_id AND NEW.type IS OLD.type
                    AND NEW.sellable_delta IS OLD.sellable_delta AND NEW.damaged_delta IS OLD.damaged_delta
                    AND NEW.source_key IS OLD.source_key AND NEW.adjustment_request_id IS OLD.adjustment_request_id
                    AND NEW.reason IS OLD.reason AND NEW.created_at IS OLD.created_at)
                BEGIN SELECT RAISE(ABORT, 'inventory transactions are immutable'); END");
            DB::statement("CREATE TRIGGER inventory_transactions_delete_guard BEFORE DELETE ON inventory_transactions
                BEGIN SELECT RAISE(ABORT, 'inventory transactions are immutable'); END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
