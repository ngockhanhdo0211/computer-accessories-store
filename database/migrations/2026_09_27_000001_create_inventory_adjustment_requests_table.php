<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_adjustment_requests')) {
            throw new RuntimeException('Inventory migration is pending but inventory_adjustment_requests already exists. Inspect it manually; no automatic cleanup is performed.');
        }

        Schema::create('inventory_adjustment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->integer('sellable_delta');
            $table->integer('damaged_delta')->default(0);
            $table->string('reason', 500);
            $table->dateTime('approved_at', 6)->nullable();
            $table->dateTime('rejected_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->index(['approved_at', 'rejected_at', 'created_at'], 'inventory_adjustments_state_created_index');
            $table->index(['product_id', 'created_at'], 'inventory_adjustments_product_created_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE inventory_adjustment_requests
                ADD CONSTRAINT inventory_adjustments_nonzero_check CHECK (sellable_delta <> 0 OR damaged_delta <> 0),
                ADD CONSTRAINT inventory_adjustments_review_state_check CHECK (
                    (approved_at IS NULL AND rejected_at IS NULL AND reviewed_by IS NULL)
                    OR (approved_at IS NOT NULL AND rejected_at IS NULL AND reviewed_by IS NOT NULL)
                    OR (approved_at IS NULL AND rejected_at IS NOT NULL AND reviewed_by IS NOT NULL)
                )');
            DB::unprepared("CREATE TRIGGER inventory_adjustments_update_guard BEFORE UPDATE ON inventory_adjustment_requests
                FOR EACH ROW BEGIN
                    IF NOT (
                        NEW.id <=> OLD.id AND NEW.product_id <=> OLD.product_id
                        AND NEW.requested_by <=> OLD.requested_by AND NEW.request_key <=> OLD.request_key
                        AND NEW.sellable_delta <=> OLD.sellable_delta AND NEW.damaged_delta <=> OLD.damaged_delta
                        AND NEW.reason <=> OLD.reason AND NEW.created_at <=> OLD.created_at
                        AND OLD.approved_at IS NULL AND OLD.rejected_at IS NULL AND OLD.reviewed_by IS NULL
                        AND NEW.reviewed_by IS NOT NULL
                        AND ((NEW.approved_at IS NOT NULL AND NEW.rejected_at IS NULL)
                            OR (NEW.approved_at IS NULL AND NEW.rejected_at IS NOT NULL))
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory adjustment requests are immutable';
                    END IF;
                END");
            DB::unprepared("CREATE TRIGGER inventory_adjustments_delete_guard BEFORE DELETE ON inventory_adjustment_requests
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory adjustment requests cannot be deleted'");
        } else {
            DB::statement("CREATE TRIGGER inventory_adjustments_insert_check BEFORE INSERT ON inventory_adjustment_requests
                WHEN (NEW.sellable_delta = 0 AND NEW.damaged_delta = 0)
                    OR NOT (
                        (NEW.approved_at IS NULL AND NEW.rejected_at IS NULL AND NEW.reviewed_by IS NULL)
                        OR (NEW.approved_at IS NOT NULL AND NEW.rejected_at IS NULL AND NEW.reviewed_by IS NOT NULL)
                        OR (NEW.approved_at IS NULL AND NEW.rejected_at IS NOT NULL AND NEW.reviewed_by IS NOT NULL)
                    )
                BEGIN SELECT RAISE(ABORT, 'inventory adjustment check failed'); END");
            DB::statement("CREATE TRIGGER inventory_adjustments_update_guard BEFORE UPDATE ON inventory_adjustment_requests
                WHEN NOT (
                    NEW.id IS OLD.id AND NEW.product_id IS OLD.product_id
                    AND NEW.requested_by IS OLD.requested_by AND NEW.request_key IS OLD.request_key
                    AND NEW.sellable_delta IS OLD.sellable_delta AND NEW.damaged_delta IS OLD.damaged_delta
                    AND NEW.reason IS OLD.reason AND NEW.created_at IS OLD.created_at
                    AND OLD.approved_at IS NULL AND OLD.rejected_at IS NULL AND OLD.reviewed_by IS NULL
                    AND NEW.reviewed_by IS NOT NULL
                    AND ((NEW.approved_at IS NOT NULL AND NEW.rejected_at IS NULL)
                        OR (NEW.approved_at IS NULL AND NEW.rejected_at IS NOT NULL))
                )
                BEGIN SELECT RAISE(ABORT, 'inventory adjustment requests are immutable'); END");
            DB::statement("CREATE TRIGGER inventory_adjustments_delete_guard BEFORE DELETE ON inventory_adjustment_requests
                BEGIN SELECT RAISE(ABORT, 'inventory adjustment requests cannot be deleted'); END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustment_requests');
    }
};
