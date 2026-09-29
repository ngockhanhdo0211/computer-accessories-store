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

        if (! Schema::hasTable('orders') || ! Schema::hasTable('users')) {
            throw new RuntimeException('Order Status History migration requires orders and users.');
        }

        if (Schema::hasTable('order_status_histories')) {
            throw new RuntimeException('Order Status History migration found a partial migration state: order_status_histories already exists. Inspect it manually; no automatic cleanup is performed.');
        }

        try {
            if (DB::getDriverName() === 'sqlite') {
                $this->createSqliteTable();
            } else {
                $this->createMariaDbOrMySqlTable();
            }

            $this->createImmutableTriggers();
        } catch (Throwable $exception) {
            throw new RuntimeException('Order Status History migration stopped while creating its table or immutable triggers. Inspect the partial state manually; no automatic cleanup is performed.', 0, $exception);
        }
    }

    public function down(): void
    {
        $this->assertSupportedDatabase();

        if (Schema::hasTable('order_status_histories')) {
            DB::statement('DROP TRIGGER IF EXISTS order_status_histories_update_guard');
            DB::statement('DROP TRIGGER IF EXISTS order_status_histories_delete_guard');
        }

        Schema::dropIfExists('order_status_histories');
    }

    private function createMariaDbOrMySqlTable(): void
    {
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnUpdate()->restrictOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnUpdate()->nullOnDelete();
            $table->text('reason')->nullable();
            $table->char('event_key', 36);
            $table->dateTime('created_at', 6);

            $table->unique(['order_id', 'event_key'], 'order_status_histories_order_event_unique');
            $table->index(['order_id', 'created_at', 'id'], 'order_status_histories_order_created_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE order_status_histories
                ADD CONSTRAINT order_status_histories_from_status_check CHECK (
                    from_status IS NULL OR BINARY from_status IN ('da_dat','cho_chuyen_phat','dang_trung_chuyen','da_giao','da_huy')
                ),
                ADD CONSTRAINT order_status_histories_to_status_check CHECK (
                    BINARY to_status IN ('da_dat','cho_chuyen_phat','dang_trung_chuyen','da_giao','da_huy')
                ),
                ADD CONSTRAINT order_status_histories_edge_check CHECK (
                    (from_status IS NULL AND BINARY to_status = 'da_dat')
                    OR (from_status IS NOT NULL AND (
                        (BINARY from_status = 'da_dat' AND BINARY to_status IN ('cho_chuyen_phat','da_huy'))
                        OR (BINARY from_status = 'cho_chuyen_phat' AND BINARY to_status IN ('dang_trung_chuyen','da_huy'))
                        OR (BINARY from_status = 'dang_trung_chuyen' AND BINARY to_status IN ('da_giao','da_huy'))
                    ))
                ),
                ADD CONSTRAINT order_status_histories_event_key_check CHECK (CHAR_LENGTH(event_key) = 36)
            SQL);
    }

    private function createSqliteTable(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE order_status_histories (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                order_id INTEGER NOT NULL,
                from_status VARCHAR(32) NULL,
                to_status VARCHAR(32) NOT NULL,
                actor_id INTEGER NULL,
                reason TEXT NULL,
                event_key CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT order_status_histories_order_id_foreign FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT order_status_histories_actor_id_foreign FOREIGN KEY (actor_id) REFERENCES users(id) ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT order_status_histories_order_event_unique UNIQUE (order_id, event_key),
                CONSTRAINT order_status_histories_from_status_check CHECK (
                    from_status IS NULL OR from_status IN ('da_dat','cho_chuyen_phat','dang_trung_chuyen','da_giao','da_huy')
                ),
                CONSTRAINT order_status_histories_to_status_check CHECK (
                    to_status IN ('da_dat','cho_chuyen_phat','dang_trung_chuyen','da_giao','da_huy')
                ),
                CONSTRAINT order_status_histories_edge_check CHECK (
                    (from_status IS NULL AND to_status = 'da_dat')
                    OR (from_status IS NOT NULL AND (
                        (from_status = 'da_dat' AND to_status IN ('cho_chuyen_phat','da_huy'))
                        OR (from_status = 'cho_chuyen_phat' AND to_status IN ('dang_trung_chuyen','da_huy'))
                        OR (from_status = 'dang_trung_chuyen' AND to_status IN ('da_giao','da_huy'))
                    ))
                ),
                CONSTRAINT order_status_histories_event_key_check CHECK (LENGTH(event_key) = 36)
            )
            SQL);

        DB::statement('CREATE INDEX order_status_histories_order_created_index ON order_status_histories (order_id, created_at, id)');
        DB::statement('CREATE INDEX order_status_histories_actor_id_index ON order_status_histories (actor_id)');
    }

    private function createImmutableTriggers(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER order_status_histories_update_guard BEFORE UPDATE ON order_status_histories
                FOR EACH ROW BEGIN
                    IF NOT (OLD.actor_id IS NOT NULL AND NEW.actor_id IS NULL
                        AND NEW.id <=> OLD.id AND NEW.order_id <=> OLD.order_id
                        AND NEW.from_status <=> OLD.from_status AND NEW.to_status <=> OLD.to_status
                        AND NEW.reason <=> OLD.reason AND NEW.event_key <=> OLD.event_key
                        AND NEW.created_at <=> OLD.created_at) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order status histories are append-only';
                    END IF;
                END");
            DB::unprepared("CREATE TRIGGER order_status_histories_delete_guard BEFORE DELETE ON order_status_histories
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order status histories are append-only'");

            return;
        }

        DB::statement("CREATE TRIGGER order_status_histories_update_guard BEFORE UPDATE ON order_status_histories
            WHEN NOT (OLD.actor_id IS NOT NULL AND NEW.actor_id IS NULL
                AND NEW.id IS OLD.id AND NEW.order_id IS OLD.order_id
                AND NEW.from_status IS OLD.from_status AND NEW.to_status IS OLD.to_status
                AND NEW.reason IS OLD.reason AND NEW.event_key IS OLD.event_key
                AND NEW.created_at IS OLD.created_at)
            BEGIN SELECT RAISE(ABORT, 'order status histories are append-only'); END");
        DB::statement("CREATE TRIGGER order_status_histories_delete_guard BEFORE DELETE ON order_status_histories
            BEGIN SELECT RAISE(ABORT, 'order status histories are append-only'); END");
    }

    private function assertSupportedDatabase(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Order Status History migration supports only SQLite, MariaDB and MySQL.');
        }
    }
};
