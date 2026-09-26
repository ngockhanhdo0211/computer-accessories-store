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

        if (Schema::hasTable('products')) {
            throw new RuntimeException('Product migration is pending but products already exists. Inspect its rows, structure and constraints manually; no automatic cleanup is performed.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteTable();

            return;
        }

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnUpdate()->restrictOnDelete();
            $table->string('sku', 80)->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('short_description');
            $table->longText('description');
            $table->unsignedBigInteger('price_vnd');
            $table->unsignedBigInteger('sale_price_vnd')->nullable();
            $table->string('visibility', 8)->default('active');
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->unsignedInteger('sellable_quantity')->default(0);
            $table->unsignedInteger('damaged_quantity')->default(0);
            $table->unsignedInteger('sold_quantity')->default(0);
            $table->timestamps();

            $table->index(['category_id', 'visibility', 'id'], 'products_category_visibility_id_index');
            $table->index(['brand_id', 'visibility', 'id'], 'products_brand_visibility_id_index');
            $table->index(['visibility', 'created_at', 'id'], 'products_visibility_created_id_index');
            $table->index(['visibility', 'price_vnd', 'id'], 'products_visibility_price_id_index');
        });

        try {
            DB::statement(<<<'SQL'
                ALTER TABLE products
                    ADD CONSTRAINT products_price_vnd_check CHECK (price_vnd > 0),
                    ADD CONSTRAINT products_sale_price_vnd_check CHECK (sale_price_vnd IS NULL OR (sale_price_vnd > 0 AND sale_price_vnd < price_vnd)),
                    ADD CONSTRAINT products_visibility_check CHECK (visibility IN ('active', 'hidden')),
                    ADD CONSTRAINT products_low_stock_threshold_check CHECK (low_stock_threshold >= 0),
                    ADD CONSTRAINT products_sellable_quantity_check CHECK (sellable_quantity >= 0),
                    ADD CONSTRAINT products_damaged_quantity_check CHECK (damaged_quantity >= 0),
                    ADD CONSTRAINT products_sold_quantity_check CHECK (sold_quantity >= 0)
            SQL);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Product migration stopped after creating products. Inspect its structure, constraints and rows manually; no automatic cleanup is performed.',
                0,
                $exception
            );
        }
    }

    private function createSqliteTable(): void
    {
        try {
            DB::statement(<<<'SQL'
                CREATE TABLE products (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    category_id INTEGER NOT NULL,
                    brand_id INTEGER NOT NULL,
                    sku VARCHAR(80) NOT NULL,
                    slug VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    short_description TEXT NOT NULL,
                    description TEXT NOT NULL,
                    price_vnd INTEGER NOT NULL,
                    sale_price_vnd INTEGER,
                    visibility VARCHAR(8) NOT NULL DEFAULT 'active',
                    low_stock_threshold INTEGER NOT NULL DEFAULT 5,
                    sellable_quantity INTEGER NOT NULL DEFAULT 0,
                    damaged_quantity INTEGER NOT NULL DEFAULT 0,
                    sold_quantity INTEGER NOT NULL DEFAULT 0,
                    created_at DATETIME,
                    updated_at DATETIME,
                    CONSTRAINT products_category_id_foreign FOREIGN KEY (category_id) REFERENCES categories(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                    CONSTRAINT products_brand_id_foreign FOREIGN KEY (brand_id) REFERENCES brands(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                    CONSTRAINT products_price_vnd_check CHECK (price_vnd > 0),
                    CONSTRAINT products_sale_price_vnd_check CHECK (sale_price_vnd IS NULL OR (sale_price_vnd > 0 AND sale_price_vnd < price_vnd)),
                    CONSTRAINT products_visibility_check CHECK (visibility IN ('active', 'hidden')),
                    CONSTRAINT products_low_stock_threshold_check CHECK (low_stock_threshold >= 0),
                    CONSTRAINT products_sellable_quantity_check CHECK (sellable_quantity >= 0),
                    CONSTRAINT products_damaged_quantity_check CHECK (damaged_quantity >= 0),
                    CONSTRAINT products_sold_quantity_check CHECK (sold_quantity >= 0)
                )
            SQL);

            Schema::table('products', function (Blueprint $table) {
                $table->unique('sku');
                $table->unique('slug');
                $table->index(['category_id', 'visibility', 'id'], 'products_category_visibility_id_index');
                $table->index(['brand_id', 'visibility', 'id'], 'products_brand_visibility_id_index');
                $table->index(['visibility', 'created_at', 'id'], 'products_visibility_created_id_index');
                $table->index(['visibility', 'price_vnd', 'id'], 'products_visibility_price_id_index');
            });
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Product migration stopped while creating products. Inspect its structure, constraints and rows manually; no automatic cleanup is performed.',
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
            throw new RuntimeException('Product migration supports only SQLite, MariaDB and MySQL.');
        }

        $rawVersion = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        $version = preg_replace('/^5\.5\.5-/', '', $rawVersion);

        if (! preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
            throw new RuntimeException('Cannot determine MariaDB/MySQL version for Product CHECK enforcement.');
        }

        $mariaDb = str_contains(strtolower($version), 'mariadb');
        $minimum = $mariaDb ? '10.2.1' : '8.0.16';

        if (version_compare($matches[1], $minimum, '<')) {
            throw new RuntimeException('Product CHECK constraints require '.($mariaDb ? 'MariaDB' : 'MySQL')." {$minimum} or newer.");
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Product migration supports only SQLite, MariaDB and MySQL.');
        }

        Schema::dropIfExists('products');
    }
};
