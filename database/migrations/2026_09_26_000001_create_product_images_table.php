<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_images')) {
            throw new RuntimeException('Product image migration is pending but product_images already exists. Inspect its rows, structure and constraints manually; no automatic cleanup is performed.');
        }

        if (! Schema::hasTable('products')) {
            throw new RuntimeException('Product image migration requires the products table.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteTable();

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Product image migration supports only SQLite, MariaDB and MySQL.');
        }

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnUpdate()->restrictOnDelete();
            $table->string('path', 500);
            $table->string('alt_text')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'path']);
            $table->index(['product_id', 'is_primary', 'sort_order'], 'product_images_product_primary_sort_index');
        });

        try {
            DB::statement(<<<'SQL'
                ALTER TABLE product_images
                    ADD CONSTRAINT product_images_is_primary_check CHECK (is_primary IN (0, 1)),
                    ADD CONSTRAINT product_images_sort_order_check CHECK (sort_order >= 0)
            SQL);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Product image migration stopped after creating product_images. Inspect its structure, constraints and rows manually; no automatic cleanup is performed.',
                0,
                $exception
            );
        }
    }

    private function createSqliteTable(): void
    {
        try {
            DB::statement(<<<'SQL'
                CREATE TABLE product_images (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    product_id INTEGER NOT NULL,
                    path VARCHAR(500) NOT NULL,
                    alt_text VARCHAR(255),
                    is_primary TINYINT(1) NOT NULL DEFAULT 0,
                    sort_order INTEGER NOT NULL DEFAULT 0,
                    created_at DATETIME,
                    updated_at DATETIME,
                    CONSTRAINT product_images_product_id_foreign FOREIGN KEY (product_id) REFERENCES products(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                    CONSTRAINT product_images_is_primary_check CHECK (is_primary IN (0, 1)),
                    CONSTRAINT product_images_sort_order_check CHECK (sort_order >= 0)
                )
            SQL);

            Schema::table('product_images', function (Blueprint $table) {
                $table->unique(['product_id', 'path']);
                $table->index(['product_id', 'is_primary', 'sort_order'], 'product_images_product_primary_sort_index');
            });
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Product image migration stopped while creating product_images. Inspect its structure, constraints and rows manually; no automatic cleanup is performed.',
                0,
                $exception
            );
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Product image migration supports only SQLite, MariaDB and MySQL.');
        }

        Schema::dropIfExists('product_images');
    }
};
