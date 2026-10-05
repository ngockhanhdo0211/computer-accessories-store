<?php

namespace Tests\Feature;

use App\Enums\ProductVisibility;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ProductDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_and_image_schema_defaults_casts_and_relations_are_valid(): void
    {
        $this->assertTrue(Schema::hasColumns('products', [
            'id', 'category_id', 'brand_id', 'sku', 'slug', 'name', 'short_description', 'description',
            'price_vnd', 'sale_price_vnd', 'visibility', 'low_stock_threshold', 'sellable_quantity',
            'damaged_quantity', 'sold_quantity', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('product_images', [
            'id', 'product_id', 'storage_provider', 'path', 'cloudinary_public_id', 'secure_url',
            'width', 'height', 'bytes', 'format', 'alt_text', 'is_primary', 'sort_order', 'created_at', 'updated_at',
        ]));

        $product = Product::factory()->create();
        $image = ProductImage::factory()->for($product)->primary()->create([
            'path' => 'products/'.$product->id.'/photo name.jpg',
        ]);

        $product->refresh();
        $this->assertSame(ProductVisibility::Active, $product->visibility);
        $this->assertSame(5, $product->low_stock_threshold);
        $this->assertSame(0, $product->sellable_quantity);
        $this->assertSame(0, $product->damaged_quantity);
        $this->assertSame(0, $product->sold_quantity);
        $this->assertIsInt($product->price_vnd);
        $this->assertTrue($image->fresh()->is_primary);
        $this->assertStringStartsWith('products/'.$product->id.'/', ProductImage::factory()->for($product)->create()->path);
        $this->assertSame('/storage/products/'.$product->id.'/photo%20name.jpg', $image->url());
        $this->assertTrue($product->category->is($product->category));
        $this->assertTrue($product->brand->is($product->brand));
        $this->assertTrue($product->images->contains($image));
        $this->assertTrue($product->primaryImage->is($image));
        $this->assertTrue($product->category->products->contains($product));
        $this->assertTrue($product->brand->products->contains($product));
        $this->assertSame('slug', $product->getRouteKeyName());
    }

    public function test_factories_create_unique_valid_active_and_hidden_products(): void
    {
        $active = Product::factory()->create();
        $hidden = Product::factory()->hidden()->create();

        $this->assertNotSame($active->sku, $hidden->sku);
        $this->assertNotSame($active->slug, $hidden->slug);
        $this->assertSame(ProductVisibility::Active, $active->visibility);
        $this->assertSame(ProductVisibility::Hidden, $hidden->visibility);
        $this->assertGreaterThan(0, $active->price_vnd);
    }

    public function test_database_enforces_product_unique_checks_and_foreign_keys(): void
    {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $valid = $this->row($category->id, $brand->id);
        DB::table('products')->insert($valid);

        $cases = [
            ['sku' => $valid['sku'], 'slug' => 'another-slug'],
            ['sku' => 'ANOTHER-SKU', 'slug' => $valid['slug']],
            ['sku' => 'NEGATIVE-PRICE', 'slug' => 'negative-price', 'price_vnd' => -1],
            ['sku' => 'BAD-SALE-ZERO', 'slug' => 'bad-sale-zero', 'sale_price_vnd' => 0],
            ['sku' => 'BAD-SALE-EQUAL', 'slug' => 'bad-sale-equal', 'sale_price_vnd' => 100000],
            ['sku' => 'BAD-SALE-HIGH', 'slug' => 'bad-sale-high', 'sale_price_vnd' => 200000],
            ['sku' => 'BAD-STATUS', 'slug' => 'bad-status', 'visibility' => 'draft'],
            ['sku' => 'NEGATIVE-THRESHOLD', 'slug' => 'negative-threshold', 'low_stock_threshold' => -1],
            ['sku' => 'NEGATIVE-STOCK', 'slug' => 'negative-stock', 'sellable_quantity' => -1],
            ['sku' => 'NEGATIVE-DAMAGED', 'slug' => 'negative-damaged', 'damaged_quantity' => -1],
            ['sku' => 'NEGATIVE-SOLD', 'slug' => 'negative-sold', 'sold_quantity' => -1],
            ['sku' => 'BAD-CATEGORY', 'slug' => 'bad-category', 'category_id' => 999999],
            ['sku' => 'BAD-BRAND', 'slug' => 'bad-brand', 'brand_id' => 999999],
        ];

        foreach ($cases as $overrides) {
            $this->assertDatabaseRejects('products', [...$valid, ...$overrides]);
        }

        $this->assertDatabaseRejects('product_images', [
            'product_id' => 999999,
            'path' => 'products/missing/image.jpg',
            'is_primary' => 0,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseRejects('product_images', [
            'product_id' => 1,
            'path' => 'products/1/bad-primary.jpg',
            'is_primary' => 2,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseRejects('product_images', [
            'product_id' => 1,
            'path' => 'products/1/bad-order.jpg',
            'is_primary' => 0,
            'sort_order' => -1,
        ]);
    }

    public function test_database_restricts_category_brand_and_product_deletes(): void
    {
        $product = Product::factory()->create();
        $image = ProductImage::factory()->for($product)->create();

        $this->assertDatabaseRejectsDelete('categories', $product->category_id);
        $this->assertDatabaseRejectsDelete('brands', $product->brand_id);
        $this->assertDatabaseRejectsDelete('products', $product->id);

        $image->delete();
        $product->delete();
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_public_scope_requires_active_product_visible_category_and_visible_brand(): void
    {
        $eligible = Product::factory()->create();
        Product::factory()->hidden()->create();
        Product::factory()->for(Category::factory()->hidden(), 'category')->create();
        Product::factory()->for(Brand::factory()->hidden(), 'brand')->create();

        $this->assertSame([$eligible->id], Product::query()->publiclyVisible()->pluck('id')->all());
    }

    public function test_sqlite_schema_contains_named_checks_and_expected_indexes(): void
    {
        $productsSql = (string) DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'products'")->sql;
        $imagesSql = (string) DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'product_images'")->sql;
        $indexes = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name IN ('products', 'product_images')"))->pluck('name');

        foreach ([
            'products_price_vnd_check', 'products_sale_price_vnd_check', 'products_visibility_check',
            'products_low_stock_threshold_check', 'products_sellable_quantity_check',
            'products_damaged_quantity_check', 'products_sold_quantity_check',
        ] as $constraint) {
            $this->assertStringContainsString($constraint, $productsSql);
        }
        foreach (['product_images_is_primary_check', 'product_images_sort_order_check', 'product_images_storage_shape_check'] as $constraint) {
            $this->assertStringContainsString($constraint, $imagesSql);
        }
        foreach ([
            'products_sku_unique', 'products_slug_unique', 'products_category_visibility_id_index',
            'products_brand_visibility_id_index', 'products_visibility_created_id_index',
            'products_visibility_price_id_index', 'product_images_product_id_path_unique',
            'product_images_product_primary_sort_index', 'product_images_cloudinary_public_id_unique',
        ] as $index) {
            $this->assertContains($index, $indexes);
        }
    }

    public function test_migrations_refuse_partial_existing_tables_without_deleting_rows(): void
    {
        $product = Product::factory()->create();
        $productMigration = require database_path('migrations/2026_09_26_000000_create_products_table.php');
        $imageMigration = require database_path('migrations/2026_09_26_000001_create_product_images_table.php');

        try {
            $productMigration->up();
            $this->fail('Expected products partial-state guard to stop migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }

        try {
            $imageMigration->up();
            $this->fail('Expected product_images partial-state guard to stop migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_isolated_down_up_preserves_existing_account_category_and_brand_tables(): void
    {
        $userCount = DB::table('users')->count();
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $imageMigration = require database_path('migrations/2026_09_26_000001_create_product_images_table.php');
        $productMigration = require database_path('migrations/2026_09_26_000000_create_products_table.php');

        $imageMigration->down();
        $productMigration->down();
        $this->assertFalse(Schema::hasTable('products'));
        $this->assertFalse(Schema::hasTable('product_images'));
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
        $this->assertSame($userCount, DB::table('users')->count());

        $productMigration->up();
        $imageMigration->up();
        $this->assertTrue(Schema::hasTable('products'));
        $this->assertTrue(Schema::hasTable('product_images'));
    }

    public function test_cloudinary_metadata_migration_down_up_preserves_local_rows_and_blocks_cloud_rows(): void
    {
        $product = Product::factory()->create();
        $local = ProductImage::factory()->for($product)->create([
            'path' => 'products/'.$product->id.'/legacy.png',
        ]);
        $migration = require database_path('migrations/2026_10_07_000000_add_cloudinary_storage_to_product_images.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('product_images', 'storage_provider'));
        $this->assertDatabaseHas('product_images', ['id' => $local->id, 'path' => $local->path]);

        $migration->up();
        $this->assertDatabaseHas('product_images', [
            'id' => $local->id,
            'storage_provider' => 'local',
            'path' => $local->path,
        ]);

        DB::table('product_images')->where('id', $local->id)->update([
            'storage_provider' => 'cloudinary',
            'path' => null,
            'cloudinary_public_id' => config('product-images.cloudinary.folder').'/products/'.$product->id.'/asset',
            'secure_url' => 'https://res.cloudinary.example/asset.png',
            'width' => 1,
            'height' => 1,
            'bytes' => 1,
            'format' => 'png',
        ]);

        try {
            $migration->down();
            $this->fail('Expected Cloudinary rollback guard.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot remove Cloudinary', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('product_images', 'storage_provider'));
        $this->assertDatabaseHas('product_images', ['id' => $local->id, 'storage_provider' => 'cloudinary']);
    }

    private function row(int $categoryId, int $brandId): array
    {
        return [
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'sku' => 'DB-VALID-001',
            'slug' => 'db-valid-001',
            'name' => 'Database valid product',
            'short_description' => 'Short description',
            'description' => 'Detailed description',
            'price_vnd' => 100000,
            'sale_price_vnd' => null,
            'visibility' => 'active',
            'low_stock_threshold' => 5,
            'sellable_quantity' => 0,
            'damaged_quantity' => 0,
            'sold_quantity' => 0,
        ];
    }

    private function assertDatabaseRejects(string $table, array $row): void
    {
        try {
            DB::table($table)->insert($row);
            $this->fail("Database accepted invalid row for {$table}.");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertDatabaseRejectsDelete(string $table, int $id): void
    {
        try {
            DB::table($table)->where('id', $id)->delete();
            $this->fail("Database deleted referenced row from {$table}.");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
