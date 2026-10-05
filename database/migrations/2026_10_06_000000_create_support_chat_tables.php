<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            throw new RuntimeException('Support Chat requires the users table.');
        }
        foreach (['support_conversations', 'support_messages', 'support_conversation_reads'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException("Support Chat migration found an existing {$table} table.");
            }
        }

        Schema::create('support_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique('support_conversations_customer_unique')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->string('status', 10)->default('open');
            $table->dateTime('last_message_at', 6)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->dateTime('closed_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['status', 'last_message_at', 'id'], 'support_conversations_inbox_index');
        });
        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->text('content');
            $table->uuid('client_message_key');
            $table->dateTime('created_at', 6);
            $table->unique(['sender_id', 'client_message_key'], 'support_messages_sender_key_unique');
            $table->index(['conversation_id', 'id'], 'support_messages_conversation_id_index');
        });
        Schema::create('support_conversation_reads', function (Blueprint $table): void {
            $table->foreignId('conversation_id')->constrained('support_conversations')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('last_read_message_id')->nullable()->constrained('support_messages')->restrictOnUpdate()->restrictOnDelete();
            $table->dateTime('read_at', 6);
            $table->primary(['conversation_id', 'user_id'], 'support_conversation_reads_primary');
        });

        $this->createGuards();
    }

    public function down(): void
    {
        foreach (['support_messages', 'support_conversation_reads', 'support_conversations'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Support Chat rollback found a partial schema state.');
            }
        }
        if (DB::table('support_messages')->exists() || DB::table('support_conversation_reads')->exists() || DB::table('support_conversations')->exists()) {
            throw new RuntimeException('Cannot rollback Support Chat while conversation evidence exists.');
        }
        $this->dropGuards();
        Schema::drop('support_conversation_reads');
        Schema::drop('support_messages');
        Schema::drop('support_conversations');
    }

    private function createGuards(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $version = strtolower((string) DB::selectOne('SELECT VERSION() AS version')->version);
            $clientKeyCheck = str_contains($version, 'mariadb')
                ? "client_message_key REGEXP BINARY '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'"
                : "REGEXP_LIKE(client_message_key, '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$', 'c')";
            DB::statement("ALTER TABLE support_conversations ADD CONSTRAINT support_conversations_shape_check CHECK (BINARY status IN ('open','closed') AND ((BINARY status = 'open' AND closed_by IS NULL AND closed_at IS NULL) OR (BINARY status = 'closed' AND closed_by IS NOT NULL AND closed_at IS NOT NULL)))");
            DB::statement("ALTER TABLE support_messages ADD CONSTRAINT support_messages_content_check CHECK (CHAR_LENGTH(content) BETWEEN 1 AND 2000 AND content REGEXP '[^[:space:]]' AND {$clientKeyCheck})");
            DB::unprepared("CREATE TRIGGER support_conversations_insert_guard BEFORE INSERT ON support_conversations FOR EACH ROW BEGIN
                IF NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.customer_id AND BINARY u.role = 'customer') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'support conversation customer is invalid'; END IF;
            END");
            DB::unprepared("CREATE TRIGGER support_conversations_update_guard BEFORE UPDATE ON support_conversations FOR EACH ROW BEGIN
                IF NOT (NEW.customer_id <=> OLD.customer_id AND NEW.created_at <=> OLD.created_at
                    AND (OLD.last_message_at IS NULL OR (NEW.last_message_at IS NOT NULL AND NEW.last_message_at >= OLD.last_message_at))
                    AND (BINARY NEW.status <> 'closed' OR EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.closed_by AND BINARY u.role IN ('admin','employee') AND BINARY u.status = 'active'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'support conversation update is invalid';
                END IF;
            END");
            DB::unprepared("CREATE TRIGGER support_conversations_delete_guard BEFORE DELETE ON support_conversations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'support conversations cannot be deleted'");
            DB::unprepared("CREATE TRIGGER support_messages_insert_guard BEFORE INSERT ON support_messages FOR EACH ROW BEGIN
                IF NOT EXISTS (SELECT 1 FROM support_conversations c JOIN users u ON u.id = NEW.sender_id WHERE c.id = NEW.conversation_id AND BINARY u.status = 'active' AND ((BINARY u.role = 'customer' AND c.customer_id = u.id) OR BINARY u.role IN ('admin','employee'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'support message sender is invalid';
                END IF;
            END");
            DB::unprepared("CREATE TRIGGER support_messages_projection_guard AFTER INSERT ON support_messages FOR EACH ROW BEGIN
                UPDATE support_conversations SET status = 'open', closed_by = NULL, closed_at = NULL,
                    last_message_at = CASE WHEN last_message_at IS NULL OR NEW.created_at > last_message_at THEN NEW.created_at ELSE last_message_at END,
                    updated_at = CASE WHEN NEW.created_at > updated_at THEN NEW.created_at ELSE updated_at END
                WHERE id = NEW.conversation_id;
            END");
            DB::unprepared("CREATE TRIGGER support_messages_update_guard BEFORE UPDATE ON support_messages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'support messages are immutable'");
            DB::unprepared("CREATE TRIGGER support_messages_delete_guard BEFORE DELETE ON support_messages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'support messages are immutable'");
            DB::unprepared("CREATE TRIGGER support_reads_insert_guard BEFORE INSERT ON support_conversation_reads FOR EACH ROW BEGIN
                IF NOT EXISTS (SELECT 1 FROM support_conversations c JOIN users u ON u.id = NEW.user_id WHERE c.id = NEW.conversation_id AND BINARY u.status = 'active' AND ((BINARY u.role = 'customer' AND c.customer_id = u.id) OR BINARY u.role IN ('admin','employee'))) OR (NEW.last_read_message_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM support_messages m WHERE m.id = NEW.last_read_message_id AND m.conversation_id = NEW.conversation_id)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'support read marker is invalid';
                END IF;
            END");
            DB::unprepared("CREATE TRIGGER support_reads_update_guard BEFORE UPDATE ON support_conversation_reads FOR EACH ROW BEGIN
                IF NOT (NEW.conversation_id <=> OLD.conversation_id AND NEW.user_id <=> OLD.user_id
                    AND EXISTS (SELECT 1 FROM support_conversations c JOIN users u ON u.id = NEW.user_id WHERE c.id = NEW.conversation_id AND BINARY u.status = 'active' AND ((BINARY u.role = 'customer' AND c.customer_id = u.id) OR BINARY u.role IN ('admin','employee')))
                    AND (OLD.last_read_message_id IS NULL OR NEW.last_read_message_id >= OLD.last_read_message_id) AND (NEW.last_read_message_id IS NULL OR EXISTS (SELECT 1 FROM support_messages m WHERE m.id = NEW.last_read_message_id AND m.conversation_id = NEW.conversation_id))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'support read marker cannot move backward';
                END IF;
            END");
            DB::unprepared("CREATE TRIGGER support_reads_delete_guard BEFORE DELETE ON support_conversation_reads FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'support read markers cannot be deleted'");

            return;
        }

        DB::statement("CREATE TRIGGER support_conversations_insert_guard BEFORE INSERT ON support_conversations WHEN NEW.status NOT IN ('open','closed') OR (NEW.status = 'open' AND (NEW.closed_by IS NOT NULL OR NEW.closed_at IS NOT NULL)) OR (NEW.status = 'closed' AND (NEW.closed_by IS NULL OR NEW.closed_at IS NULL)) OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.customer_id AND u.role = 'customer') BEGIN SELECT RAISE(ABORT, 'support conversation is invalid'); END");
        DB::statement("CREATE TRIGGER support_conversations_update_guard BEFORE UPDATE ON support_conversations WHEN NEW.customer_id IS NOT OLD.customer_id OR NEW.created_at IS NOT OLD.created_at OR (OLD.last_message_at IS NOT NULL AND (NEW.last_message_at IS NULL OR NEW.last_message_at < OLD.last_message_at)) OR NEW.status NOT IN ('open','closed') OR (NEW.status = 'open' AND (NEW.closed_by IS NOT NULL OR NEW.closed_at IS NOT NULL)) OR (NEW.status = 'closed' AND (NEW.closed_by IS NULL OR NEW.closed_at IS NULL OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.closed_by AND u.role IN ('admin','employee') AND u.status = 'active'))) BEGIN SELECT RAISE(ABORT, 'support conversation update is invalid'); END");
        DB::statement("CREATE TRIGGER support_conversations_delete_guard BEFORE DELETE ON support_conversations BEGIN SELECT RAISE(ABORT, 'support conversations cannot be deleted'); END");
        DB::statement("CREATE TRIGGER support_messages_insert_guard BEFORE INSERT ON support_messages WHEN LENGTH(TRIM(NEW.content, char(9) || char(10) || char(11) || char(12) || char(13) || char(32) || char(160))) NOT BETWEEN 1 AND 2000 OR LENGTH(NEW.client_message_key) <> 36 OR NEW.client_message_key GLOB '*[^0-9a-f-]*' OR SUBSTR(NEW.client_message_key,9,1) <> '-' OR SUBSTR(NEW.client_message_key,14,1) <> '-' OR SUBSTR(NEW.client_message_key,19,1) <> '-' OR SUBSTR(NEW.client_message_key,24,1) <> '-' OR SUBSTR(NEW.client_message_key,15,1) NOT IN ('1','2','3','4','5') OR SUBSTR(NEW.client_message_key,20,1) NOT IN ('8','9','a','b') OR NOT EXISTS (SELECT 1 FROM support_conversations c JOIN users u ON u.id = NEW.sender_id WHERE c.id = NEW.conversation_id AND u.status = 'active' AND ((u.role = 'customer' AND c.customer_id = u.id) OR u.role IN ('admin','employee'))) BEGIN SELECT RAISE(ABORT, 'support message is invalid'); END");
        DB::statement("CREATE TRIGGER support_messages_projection_guard AFTER INSERT ON support_messages BEGIN UPDATE support_conversations SET status = 'open', closed_by = NULL, closed_at = NULL, last_message_at = CASE WHEN last_message_at IS NULL OR NEW.created_at > last_message_at THEN NEW.created_at ELSE last_message_at END, updated_at = CASE WHEN NEW.created_at > updated_at THEN NEW.created_at ELSE updated_at END WHERE id = NEW.conversation_id; END");
        DB::statement("CREATE TRIGGER support_messages_update_guard BEFORE UPDATE ON support_messages BEGIN SELECT RAISE(ABORT, 'support messages are immutable'); END");
        DB::statement("CREATE TRIGGER support_messages_delete_guard BEFORE DELETE ON support_messages BEGIN SELECT RAISE(ABORT, 'support messages are immutable'); END");
        DB::statement("CREATE TRIGGER support_reads_insert_guard BEFORE INSERT ON support_conversation_reads WHEN NOT EXISTS (SELECT 1 FROM support_conversations c JOIN users u ON u.id = NEW.user_id WHERE c.id = NEW.conversation_id AND u.status = 'active' AND ((u.role = 'customer' AND c.customer_id = u.id) OR u.role IN ('admin','employee'))) OR (NEW.last_read_message_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM support_messages m WHERE m.id = NEW.last_read_message_id AND m.conversation_id = NEW.conversation_id)) BEGIN SELECT RAISE(ABORT, 'support read marker is invalid'); END");
        DB::statement("CREATE TRIGGER support_reads_update_guard BEFORE UPDATE ON support_conversation_reads WHEN NEW.conversation_id IS NOT OLD.conversation_id OR NEW.user_id IS NOT OLD.user_id OR NOT EXISTS (SELECT 1 FROM support_conversations c JOIN users u ON u.id = NEW.user_id WHERE c.id = NEW.conversation_id AND u.status = 'active' AND ((u.role = 'customer' AND c.customer_id = u.id) OR u.role IN ('admin','employee'))) OR (OLD.last_read_message_id IS NOT NULL AND (NEW.last_read_message_id IS NULL OR NEW.last_read_message_id < OLD.last_read_message_id)) OR (NEW.last_read_message_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM support_messages m WHERE m.id = NEW.last_read_message_id AND m.conversation_id = NEW.conversation_id)) BEGIN SELECT RAISE(ABORT, 'support read marker cannot move backward'); END");
        DB::statement("CREATE TRIGGER support_reads_delete_guard BEFORE DELETE ON support_conversation_reads BEGIN SELECT RAISE(ABORT, 'support read markers cannot be deleted'); END");
    }

    private function dropGuards(): void
    {
        foreach (['support_conversations_insert_guard', 'support_conversations_update_guard', 'support_conversations_delete_guard',
            'support_messages_insert_guard', 'support_messages_projection_guard', 'support_messages_update_guard', 'support_messages_delete_guard',
            'support_reads_insert_guard', 'support_reads_update_guard', 'support_reads_delete_guard'] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
