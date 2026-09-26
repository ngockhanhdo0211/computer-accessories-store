<?php

namespace Tests\Feature;

use App\Models\Brand;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BrandDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_defaults_and_factory_are_valid(): void
    {
        $this->assertSame(
            ['id', 'name', 'slug', 'is_visible', 'created_at', 'updated_at'],
            Schema::getColumnListing('brands')
        );

        DB::table('brands')->insert([
            'name' => 'Default visibility',
            'slug' => 'default-visibility',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, (int) DB::table('brands')->where('slug', 'default-visibility')->value('is_visible'));
        $this->assertTrue(Brand::factory()->create()->is_visible);
    }

    public function test_database_requires_name(): void
    {
        $this->expectException(QueryException::class);

        DB::table('brands')->insert([
            'slug' => 'missing-name',
            'is_visible' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_duplicate_slug(): void
    {
        Brand::factory()->create(['slug' => 'duplicate']);
        $this->expectException(QueryException::class);
        Brand::factory()->create(['slug' => 'duplicate']);
    }

    public function test_database_rejects_visibility_outside_boolean_domain(): void
    {
        $this->expectException(QueryException::class);

        DB::table('brands')->insert([
            'name' => 'Invalid visibility',
            'slug' => 'invalid-visibility',
            'is_visible' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_boolean_cast_visible_scope_and_factory_states_work(): void
    {
        $visible = Brand::factory()->visible()->create();
        $hidden = Brand::factory()->hidden()->create();

        $this->assertTrue($visible->is_visible);
        $this->assertFalse($hidden->is_visible);
        $this->assertSame([$visible->id], Brand::query()->visible()->pluck('id')->all());
    }

    public function test_sqlite_schema_contains_named_visibility_check(): void
    {
        $sql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'brands')->value('sql');

        $this->assertStringContainsString('brands_is_visible_check', $sql);
        $this->assertStringContainsString('is_visible IN (0, 1)', $sql);
    }

    public function test_isolated_down_up_preserves_other_tables(): void
    {
        $migration = require database_path('migrations/2026_09_24_000000_create_brands_table.php');
        $this->assertSame(0, Brand::query()->count());

        $migration->down();
        $this->assertFalse(Schema::hasTable('brands'));
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('categories'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('brands'));
        $this->assertTrue(Schema::hasColumn('brands', 'slug'));
    }

    public function test_migration_refuses_existing_table_without_deleting_rows(): void
    {
        $brand = Brand::factory()->create();
        $migration = require database_path('migrations/2026_09_24_000000_create_brands_table.php');

        try {
            $migration->up();
            $this->fail('Expected existing brands table to stop migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no automatic cleanup', $exception->getMessage());
            $this->assertDatabaseHas('brands', ['id' => $brand->id]);
        }
    }
}
