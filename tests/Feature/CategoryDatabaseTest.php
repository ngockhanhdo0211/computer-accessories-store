<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CategoryDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_schema_and_factory_defaults_are_valid(): void
    {
        $this->assertTrue(Schema::hasColumns('categories', ['id', 'parent_id', 'name', 'slug', 'is_visible', 'created_at', 'updated_at']));
        $category = Category::factory()->create();
        $this->assertNull($category->parent_id);
        $this->assertTrue($category->is_visible);
        $this->assertNull($category->parent);
    }

    public function test_parent_and_children_relations_work(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $this->assertTrue($child->parent->is($parent));
        $this->assertTrue($parent->children->first()->is($child));
    }

    public function test_database_rejects_duplicate_slug(): void
    {
        Category::factory()->create(['slug' => 'ban-phim']);
        $this->expectException(QueryException::class);
        Category::factory()->create(['slug' => 'ban-phim']);
    }

    public function test_database_rejects_missing_parent(): void
    {
        $this->expectException(QueryException::class);
        Category::factory()->create(['parent_id' => 999999]);
    }

    public function test_database_rejects_self_parent(): void
    {
        $category = Category::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('categories')->where('id', $category->id)->update(['parent_id' => $category->id]);
    }

    public function test_database_rejects_visibility_outside_boolean_domain(): void
    {
        $this->expectException(QueryException::class);
        DB::table('categories')->insert([
            'name' => 'Invalid',
            'slug' => 'invalid',
            'is_visible' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_restricts_deleting_parent_with_children(): void
    {
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);
        $this->expectException(QueryException::class);
        DB::table('categories')->where('id', $parent->id)->delete();
    }

    public function test_visible_scope_and_hidden_cast_work(): void
    {
        Category::factory()->create();
        $hidden = Category::factory()->hidden()->create();
        $this->assertFalse($hidden->is_visible);
        $this->assertSame(1, Category::query()->visible()->count());
        $this->assertFalse(in_array('deleted_at', Schema::getColumnListing('categories'), true));
    }

    public function test_sqlite_rejects_explicit_self_parent_insert(): void
    {
        $this->expectException(QueryException::class);
        DB::table('categories')->insert([
            'id' => 1001,
            'parent_id' => 1001,
            'name' => 'Invalid',
            'slug' => 'invalid-self-parent',
            'is_visible' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sqlite_triggers_allow_valid_insert_update_and_null_parent(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        DB::table('categories')->where('id', $child->id)->update(['parent_id' => null]);
        $this->assertNull($child->fresh()->parent_id);
        DB::table('categories')->where('id', $child->id)->update(['parent_id' => $root->id]);
        $this->assertSame($root->id, $child->fresh()->parent_id);
    }

    public function test_migration_refuses_existing_table_without_deleting_rows(): void
    {
        $category = Category::factory()->create();
        $migration = require database_path('migrations/2026_09_17_000000_create_categories_table.php');
        try {
            $migration->up();
            $this->fail('Expected the existing categories table to stop migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no automatic cleanup', $exception->getMessage());
            $this->assertDatabaseHas('categories', ['id' => $category->id]);
        }
    }

    public function test_isolated_sqlite_down_up_removes_then_restores_triggers_on_empty_table(): void
    {
        $migration = require database_path('migrations/2026_09_17_000000_create_categories_table.php');
        $triggers = fn () => DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'categories')->count();
        $this->assertSame(4, $triggers());
        $this->assertSame(0, Category::query()->count());
        $migration->down();
        $this->assertFalse(Schema::hasTable('categories'));
        $this->assertSame(0, $triggers());
        $migration->up();
        $this->assertTrue(Schema::hasTable('categories'));
        $this->assertSame(4, $triggers());
    }

    public function test_partial_trigger_installation_keeps_table_and_foreign_trigger_for_manual_review(): void
    {
        $migration = require database_path('migrations/2026_09_17_000000_create_categories_table.php');
        $this->assertSame(0, Category::query()->count());
        $migration->down();

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER categories_parent_not_self_insert
            BEFORE INSERT ON users
            BEGIN SELECT 1; END;
        SQL);

        try {
            $migration->up();
            $this->fail('Expected a trigger name collision.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no automatic cleanup', $exception->getMessage());
            $this->assertTrue(Schema::hasTable('categories'));
            $this->assertSame(
                'users',
                DB::table('sqlite_master')->where('type', 'trigger')
                    ->where('name', 'categories_parent_not_self_insert')->value('tbl_name')
            );
        }

        try {
            $migration->down();
            $this->fail('Expected trigger ownership check.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('belongs to another table', $exception->getMessage());
        }

        DB::unprepared('DROP TRIGGER categories_parent_not_self_insert');
        $migration->down();
        $migration->up();
        $this->assertTrue(Schema::hasTable('categories'));
    }
}
