<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            throw new RuntimeException('Inventory migration is pending but audit_logs already exists. Inspect it manually; no automatic cleanup is performed.');
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnUpdate()->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->uuid('request_id')->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['subject_type', 'subject_id', 'created_at'], 'audit_logs_subject_created_index');
            $table->index(['actor_id', 'created_at'], 'audit_logs_actor_created_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER audit_logs_update_guard BEFORE UPDATE ON audit_logs
                FOR EACH ROW BEGIN
                    IF NOT (OLD.actor_id IS NOT NULL AND NEW.actor_id IS NULL
                        AND NEW.id <=> OLD.id AND NEW.action <=> OLD.action
                        AND NEW.subject_type <=> OLD.subject_type AND NEW.subject_id <=> OLD.subject_id
                        AND NEW.before_json <=> OLD.before_json AND NEW.after_json <=> OLD.after_json
                        AND NEW.request_id <=> OLD.request_id AND NEW.created_at <=> OLD.created_at) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit logs are immutable';
                    END IF;
                END");
            DB::unprepared("CREATE TRIGGER audit_logs_delete_guard BEFORE DELETE ON audit_logs
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit logs are immutable'");
        } else {
            DB::statement("CREATE TRIGGER audit_logs_update_guard BEFORE UPDATE ON audit_logs
                WHEN NOT (OLD.actor_id IS NOT NULL AND NEW.actor_id IS NULL
                    AND NEW.id IS OLD.id AND NEW.action IS OLD.action
                    AND NEW.subject_type IS OLD.subject_type AND NEW.subject_id IS OLD.subject_id
                    AND NEW.before_json IS OLD.before_json AND NEW.after_json IS OLD.after_json
                    AND NEW.request_id IS OLD.request_id AND NEW.created_at IS OLD.created_at)
                BEGIN SELECT RAISE(ABORT, 'audit logs are immutable'); END");
            DB::statement("CREATE TRIGGER audit_logs_delete_guard BEFORE DELETE ON audit_logs
                BEGIN SELECT RAISE(ABORT, 'audit logs are immutable'); END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
