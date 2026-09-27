<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cart_items')) {
            throw new RuntimeException('Cannot create cart_items because the table already exists. Resolve the partial migration state first.');
        }

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['user_id', 'product_id'], 'cart_items_user_product_unique');
            $table->index(['user_id', 'id'], 'cart_items_user_id_index');
        });

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_check CHECK (quantity > 0)');

            return;
        }

        if ($driver !== 'sqlite') {
            throw new RuntimeException("Unsupported database driver [$driver] for cart item domain checks.");
        }

        // SQLite cannot add or drop a CHECK constraint after CREATE. These
        // named triggers enforce the same invariant without rebuilding data.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER cart_items_quantity_check_insert
BEFORE INSERT ON cart_items
FOR EACH ROW WHEN NEW.quantity <= 0
BEGIN
    SELECT RAISE(ABORT, 'cart_items_quantity_check');
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER cart_items_quantity_check_update
BEFORE UPDATE OF quantity ON cart_items
FOR EACH ROW WHEN NEW.quantity <= 0
BEGIN
    SELECT RAISE(ABORT, 'cart_items_quantity_check');
END
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
