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

        if (Schema::hasTable('categories')) {
            throw new RuntimeException('Category migration is pending but categories already exists. Inspect its rows, structure and triggers manually; no automatic cleanup is performed.');
        }

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_visible')->default(true)->index();
            $table->timestamps();
            $table->foreign('parent_id')->references('id')->on('categories')->restrictOnUpdate()->restrictOnDelete();
        });

        try {
            $this->addDomainConstraints();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Category migration stopped after creating categories. Inspect its structure, constraints, triggers and rows manually; no automatic cleanup is performed.',
                0,
                $exception
            );
        }
    }

    private function assertSupportedDatabase(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Category migration supports only SQLite, MariaDB and MySQL.');
        }

        $rawVersion = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        $version = preg_replace('/^5\.5\.5-/', '', $rawVersion);

        if (! preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
            throw new RuntimeException('Cannot determine MariaDB/MySQL version for Category CHECK enforcement.');
        }

        $mariaDb = str_contains(strtolower($version), 'mariadb');
        $minimum = $mariaDb ? '10.2.1' : '8.0.16';

        if (version_compare($matches[1], $minimum, '<')) {
            throw new RuntimeException('Category CHECK constraints require '.($mariaDb ? 'MariaDB' : 'MySQL')." {$minimum} or newer.");
        }
    }

    private function addDomainConstraints(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER categories_parent_not_self_insert
                BEFORE INSERT ON categories
                WHEN NEW.parent_id IS NOT NULL AND NEW.parent_id = NEW.id
                BEGIN SELECT RAISE(ABORT, 'categories_parent_not_self_check'); END;
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER categories_parent_not_self_update
                BEFORE UPDATE OF parent_id, id ON categories
                WHEN NEW.parent_id IS NOT NULL AND NEW.parent_id = NEW.id
                BEGIN SELECT RAISE(ABORT, 'categories_parent_not_self_check'); END;
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER categories_visibility_insert
                BEFORE INSERT ON categories
                WHEN NEW.is_visible NOT IN (0, 1)
                BEGIN SELECT RAISE(ABORT, 'categories_is_visible_check'); END;
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER categories_visibility_update
                BEFORE UPDATE OF is_visible ON categories
                WHEN NEW.is_visible NOT IN (0, 1)
                BEGIN SELECT RAISE(ABORT, 'categories_is_visible_check'); END;
            SQL);

            return;
        }

        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_is_visible_check CHECK (is_visible IN (0, 1))');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER categories_parent_not_self_insert
            AFTER INSERT ON categories
            FOR EACH ROW
            BEGIN
                IF NEW.parent_id IS NOT NULL AND NEW.parent_id = NEW.id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'categories_parent_not_self_check';
                END IF;
            END
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER categories_parent_not_self_update
            BEFORE UPDATE ON categories
            FOR EACH ROW
            BEGIN
                IF NEW.parent_id IS NOT NULL AND NEW.parent_id = NEW.id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'categories_parent_not_self_check';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->assertTriggerOwnership([
                'categories_parent_not_self_insert',
                'categories_parent_not_self_update',
                'categories_visibility_insert',
                'categories_visibility_update',
            ]);
            DB::unprepared('DROP TRIGGER IF EXISTS categories_parent_not_self_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS categories_parent_not_self_update');
            DB::unprepared('DROP TRIGGER IF EXISTS categories_visibility_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS categories_visibility_update');
        } elseif (DB::getDriverName() === 'mysql') {
            $this->assertTriggerOwnership([
                'categories_parent_not_self_insert',
                'categories_parent_not_self_update',
            ]);
            DB::unprepared('DROP TRIGGER IF EXISTS `categories_parent_not_self_insert`');
            DB::unprepared('DROP TRIGGER IF EXISTS `categories_parent_not_self_update`');
        } else {
            throw new RuntimeException('Category migration supports only SQLite, MariaDB and MySQL.');
        }

        Schema::dropIfExists('categories');
    }

    private function assertTriggerOwnership(array $names): void
    {
        foreach ($names as $name) {
            $owner = DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->value('tbl_name')
                : DB::selectOne(
                    'SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
                    [$name]
                )?->EVENT_OBJECT_TABLE;

            if ($owner !== null && $owner !== 'categories') {
                throw new RuntimeException("Trigger {$name} belongs to another table; Category migration will not drop it.");
            }
        }
    }
};
