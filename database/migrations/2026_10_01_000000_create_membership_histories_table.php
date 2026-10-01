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

        if (! Schema::hasTable('users')) {
            throw new RuntimeException('Membership History migration requires users.');
        }
        if (Schema::hasTable('membership_histories')) {
            throw new RuntimeException('Membership History migration found a partial migration state. Inspect it manually; no automatic cleanup is performed.');
        }

        try {
            DB::getDriverName() === 'sqlite' ? $this->createSqliteTable() : $this->createMariaDbOrMySqlTable();
            $this->createImmutableTriggers();
        } catch (Throwable $exception) {
            throw new RuntimeException('Membership History migration stopped in a partial state. Inspect the table and triggers manually; no automatic cleanup is performed.', 0, $exception);
        }
    }

    public function down(): void
    {
        $this->assertSupportedDatabase();
        if (! Schema::hasTable('membership_histories')) {
            return;
        }
        if (DB::table('membership_histories')->exists()) {
            throw new RuntimeException('Cannot remove Membership History while historical rows exist. No history was deleted.');
        }

        DB::statement('DROP TRIGGER IF EXISTS membership_histories_update_guard');
        DB::statement('DROP TRIGGER IF EXISTS membership_histories_delete_guard');
        Schema::drop('membership_histories');
    }

    private function createMariaDbOrMySqlTable(): void
    {
        Schema::create('membership_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->string('old_tier', 16)->nullable();
            $table->string('new_tier', 16);
            $table->unsignedBigInteger('spending_vnd');
            $table->string('reason', 40);
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnUpdate()->nullOnDelete();
            $table->dateTime('created_at', 6);
            $table->index(['user_id', 'created_at', 'id'], 'membership_histories_user_created_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE membership_histories
                ADD CONSTRAINT membership_histories_old_tier_check CHECK (old_tier IS NULL OR BINARY old_tier IN ('dong','bac','vang','kim_cuong')),
                ADD CONSTRAINT membership_histories_new_tier_check CHECK (BINARY new_tier IN ('dong','bac','vang','kim_cuong')),
                ADD CONSTRAINT membership_histories_tier_change_check CHECK (old_tier IS NULL OR BINARY old_tier <> BINARY new_tier),
                ADD CONSTRAINT membership_histories_spending_check CHECK (spending_vnd >= 0),
                ADD CONSTRAINT membership_histories_reason_check CHECK (CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 40)
            SQL);
    }

    private function createSqliteTable(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE membership_histories (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                user_id INTEGER NOT NULL,
                old_tier VARCHAR(16) NULL,
                new_tier VARCHAR(16) NOT NULL,
                spending_vnd INTEGER NOT NULL,
                reason VARCHAR(40) NOT NULL,
                requested_by INTEGER NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT membership_histories_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT membership_histories_requested_by_foreign FOREIGN KEY (requested_by) REFERENCES users(id) ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT membership_histories_old_tier_check CHECK (old_tier IS NULL OR old_tier IN ('dong','bac','vang','kim_cuong')),
                CONSTRAINT membership_histories_new_tier_check CHECK (new_tier IN ('dong','bac','vang','kim_cuong')),
                CONSTRAINT membership_histories_tier_change_check CHECK (old_tier IS NULL OR old_tier <> new_tier),
                CONSTRAINT membership_histories_spending_check CHECK (spending_vnd >= 0),
                CONSTRAINT membership_histories_reason_check CHECK (LENGTH(TRIM(reason)) BETWEEN 1 AND 40)
            )
            SQL);
        DB::statement('CREATE INDEX membership_histories_user_created_index ON membership_histories (user_id, created_at, id)');
        DB::statement('CREATE INDEX membership_histories_requested_by_index ON membership_histories (requested_by)');
    }

    private function createImmutableTriggers(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->createMySqlImmutableTriggers();

            return;
        }

        $this->createSqliteImmutableTriggers();
    }

    private function createMySqlImmutableTriggers(): void
    {
        $update = <<<'SQL'
            CREATE TRIGGER membership_histories_update_guard BEFORE UPDATE ON membership_histories
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'membership histories are append-only'
            SQL;
        $delete = <<<'SQL'
            CREATE TRIGGER membership_histories_delete_guard BEFORE DELETE ON membership_histories
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'membership histories are append-only'
            SQL;
        DB::unprepared($update);
        DB::unprepared($delete);
    }

    private function createSqliteImmutableTriggers(): void
    {
        $update = <<<'SQL'
            CREATE TRIGGER membership_histories_update_guard BEFORE UPDATE ON membership_histories
            WHEN NOT (OLD.requested_by IS NOT NULL AND NEW.requested_by IS NULL
                AND NEW.id IS OLD.id AND NEW.user_id IS OLD.user_id
                AND NEW.old_tier IS OLD.old_tier AND NEW.new_tier IS OLD.new_tier
                AND NEW.spending_vnd IS OLD.spending_vnd AND NEW.reason IS OLD.reason
                AND NEW.created_at IS OLD.created_at)
            BEGIN SELECT RAISE(ABORT, 'membership histories are append-only'); END
            SQL;
        $delete = <<<'SQL'
            CREATE TRIGGER membership_histories_delete_guard BEFORE DELETE ON membership_histories
            BEGIN SELECT RAISE(ABORT, 'membership histories are append-only'); END
            SQL;
        DB::statement($update);
        DB::statement($delete);
    }

    private function assertSupportedDatabase(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Membership History migration supports only SQLite, MariaDB and MySQL.');
        }
    }
};
