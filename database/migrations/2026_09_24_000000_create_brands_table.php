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

        if (Schema::hasTable('brands')) {
            throw new RuntimeException('Brand migration is pending but brands already exists. Inspect its rows, structure and constraints manually; no automatic cleanup is performed.');
        }

        if (DB::getDriverName() === 'sqlite') {
            try {
                DB::statement(<<<'SQL'
                    CREATE TABLE brands (
                        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                        name VARCHAR(255) NOT NULL,
                        slug VARCHAR(255) NOT NULL,
                        is_visible TINYINT(1) NOT NULL DEFAULT 1,
                        created_at DATETIME,
                        updated_at DATETIME,
                        CONSTRAINT brands_is_visible_check CHECK (is_visible IN (0, 1))
                    )
                SQL);
                Schema::table('brands', function (Blueprint $table) {
                    $table->unique('slug');
                    $table->index('is_visible');
                });
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'Brand migration stopped while creating brands. Inspect its structure, constraints and rows manually; no automatic cleanup is performed.',
                    0,
                    $exception
                );
            }

            return;
        }

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_visible')->default(true)->index();
            $table->timestamps();
        });

        try {
            DB::statement('ALTER TABLE brands ADD CONSTRAINT brands_is_visible_check CHECK (is_visible IN (0, 1))');
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Brand migration stopped after creating brands. Inspect its structure, constraints and rows manually; no automatic cleanup is performed.',
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
            throw new RuntimeException('Brand migration supports only SQLite, MariaDB and MySQL.');
        }

        $rawVersion = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        $version = preg_replace('/^5\.5\.5-/', '', $rawVersion);

        if (! preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
            throw new RuntimeException('Cannot determine MariaDB/MySQL version for Brand CHECK enforcement.');
        }

        $mariaDb = str_contains(strtolower($version), 'mariadb');
        $minimum = $mariaDb ? '10.2.1' : '8.0.16';

        if (version_compare($matches[1], $minimum, '<')) {
            throw new RuntimeException('Brand CHECK constraints require '.($mariaDb ? 'MariaDB' : 'MySQL')." {$minimum} or newer.");
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Brand migration supports only SQLite, MariaDB and MySQL.');
        }

        Schema::dropIfExists('brands');
    }
};
