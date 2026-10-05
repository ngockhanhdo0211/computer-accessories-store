<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'storage_provider', 'cloudinary_public_id', 'secure_url', 'width', 'height', 'bytes', 'format',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('product_images')) {
            throw new RuntimeException('Cloudinary Product image migration requires product_images.');
        }
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('product_images', $column)) {
                throw new RuntimeException('Cloudinary Product image migration found a partial schema state.');
            }
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqlite(true);

            return;
        }
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Cloudinary Product image migration supports only SQLite, MariaDB and MySQL.');
        }

        DB::statement(<<<'SQL'
            ALTER TABLE product_images
                MODIFY path VARCHAR(500) NULL,
                ADD COLUMN storage_provider VARCHAR(16) NOT NULL DEFAULT 'local' AFTER product_id,
                ADD COLUMN cloudinary_public_id VARCHAR(500) NULL AFTER path,
                ADD COLUMN secure_url VARCHAR(1000) NULL AFTER cloudinary_public_id,
                ADD COLUMN width INT UNSIGNED NULL AFTER secure_url,
                ADD COLUMN height INT UNSIGNED NULL AFTER width,
                ADD COLUMN bytes BIGINT UNSIGNED NULL AFTER height,
                ADD COLUMN format VARCHAR(10) NULL AFTER bytes,
                ADD CONSTRAINT product_images_storage_shape_check CHECK (
                    (BINARY storage_provider = 'local' AND path IS NOT NULL
                        AND cloudinary_public_id IS NULL AND secure_url IS NULL
                        AND width IS NULL AND height IS NULL AND bytes IS NULL AND format IS NULL)
                    OR
                    (BINARY storage_provider = 'cloudinary' AND path IS NULL
                        AND cloudinary_public_id IS NOT NULL AND cloudinary_public_id <> ''
                        AND cloudinary_public_id NOT LIKE '/%' AND cloudinary_public_id NOT LIKE '%/'
                        AND cloudinary_public_id NOT LIKE '%..%' AND LOCATE(CHAR(92), cloudinary_public_id) = 0
                        AND secure_url LIKE 'https://%' AND width > 0 AND height > 0 AND bytes > 0
                        AND BINARY format IN ('jpg','png','webp'))
                ),
                ADD UNIQUE INDEX product_images_cloudinary_public_id_unique (cloudinary_public_id)
            SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_images')) {
            return;
        }
        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('product_images', $column)) {
                throw new RuntimeException('Cloudinary Product image rollback found a partial schema state.');
            }
        }
        if (DB::table('product_images')->where('storage_provider', '!=', 'local')->exists()
            || DB::table('product_images')->where(function ($query): void {
                $query->whereNotNull('cloudinary_public_id')
                    ->orWhereNotNull('secure_url')
                    ->orWhereNotNull('width')
                    ->orWhereNotNull('height')
                    ->orWhereNotNull('bytes')
                    ->orWhereNotNull('format');
            })->exists()) {
            throw new RuntimeException('Cannot remove Cloudinary Product image metadata while Cloudinary rows exist.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqlite(false);

            return;
        }
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Cloudinary Product image migration supports only SQLite, MariaDB and MySQL.');
        }

        DB::statement(<<<'SQL'
            ALTER TABLE product_images
                DROP INDEX product_images_cloudinary_public_id_unique,
                DROP CONSTRAINT product_images_storage_shape_check,
                DROP COLUMN format,
                DROP COLUMN bytes,
                DROP COLUMN height,
                DROP COLUMN width,
                DROP COLUMN secure_url,
                DROP COLUMN cloudinary_public_id,
                DROP COLUMN storage_provider,
                MODIFY path VARCHAR(500) NOT NULL
            SQL);
    }

    private function rebuildSqlite(bool $withCloudinary): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            DB::statement('ALTER TABLE product_images RENAME TO product_images_storage_old');
            DB::statement($withCloudinary ? $this->sqliteCloudinaryTable() : $this->sqliteLocalTable());
            if ($withCloudinary) {
                DB::statement("INSERT INTO product_images (id, product_id, storage_provider, path, alt_text, is_primary, sort_order, created_at, updated_at)
                    SELECT id, product_id, 'local', path, alt_text, is_primary, sort_order, created_at, updated_at FROM product_images_storage_old");
            } else {
                DB::statement('INSERT INTO product_images (id, product_id, path, alt_text, is_primary, sort_order, created_at, updated_at)
                    SELECT id, product_id, path, alt_text, is_primary, sort_order, created_at, updated_at FROM product_images_storage_old');
            }
            DB::statement('DROP TABLE product_images_storage_old');
            DB::statement('CREATE UNIQUE INDEX product_images_product_id_path_unique ON product_images (product_id, path)');
            DB::statement('CREATE INDEX product_images_product_primary_sort_index ON product_images (product_id, is_primary, sort_order)');
            if ($withCloudinary) {
                DB::statement('CREATE UNIQUE INDEX product_images_cloudinary_public_id_unique ON product_images (cloudinary_public_id)');
            }
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    private function sqliteCloudinaryTable(): string
    {
        return <<<'SQL'
            CREATE TABLE product_images (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                product_id INTEGER NOT NULL,
                storage_provider VARCHAR(16) NOT NULL DEFAULT 'local',
                path VARCHAR(500), cloudinary_public_id VARCHAR(500), secure_url VARCHAR(1000),
                width INTEGER, height INTEGER, bytes INTEGER, format VARCHAR(10), alt_text VARCHAR(255),
                is_primary TINYINT(1) NOT NULL DEFAULT 0, sort_order INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME, updated_at DATETIME,
                CONSTRAINT product_images_product_id_foreign FOREIGN KEY (product_id) REFERENCES products(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT product_images_is_primary_check CHECK (is_primary IN (0, 1)),
                CONSTRAINT product_images_sort_order_check CHECK (sort_order >= 0),
                CONSTRAINT product_images_storage_shape_check CHECK (
                    (storage_provider = 'local' AND path IS NOT NULL AND cloudinary_public_id IS NULL AND secure_url IS NULL AND width IS NULL AND height IS NULL AND bytes IS NULL AND format IS NULL)
                    OR (storage_provider = 'cloudinary' AND path IS NULL AND cloudinary_public_id IS NOT NULL AND cloudinary_public_id <> ''
                        AND cloudinary_public_id NOT LIKE '/%' AND cloudinary_public_id NOT LIKE '%/' AND cloudinary_public_id NOT LIKE '%..%'
                        AND INSTR(cloudinary_public_id, CHAR(92)) = 0 AND secure_url LIKE 'https://%'
                        AND width > 0 AND height > 0 AND bytes > 0 AND format IN ('jpg','png','webp')))
            )
            SQL;
    }

    private function sqliteLocalTable(): string
    {
        return <<<'SQL'
            CREATE TABLE product_images (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, product_id INTEGER NOT NULL,
                path VARCHAR(500) NOT NULL, alt_text VARCHAR(255), is_primary TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INTEGER NOT NULL DEFAULT 0, created_at DATETIME, updated_at DATETIME,
                CONSTRAINT product_images_product_id_foreign FOREIGN KEY (product_id) REFERENCES products(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT product_images_is_primary_check CHECK (is_primary IN (0, 1)),
                CONSTRAINT product_images_sort_order_check CHECK (sort_order >= 0))
            SQL;
    }
};
