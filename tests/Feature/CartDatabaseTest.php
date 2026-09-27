<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OverflowException;
use RuntimeException;
use Tests\TestCase;
use UnexpectedValueException;

class CartDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_factory_casts_and_relationships_are_valid(): void
    {
        $item = CartItem::factory()->create(['quantity' => 2]);

        $this->assertTrue(Schema::hasColumns('cart_items', ['id', 'user_id', 'product_id', 'quantity', 'created_at', 'updated_at']));
        $this->assertSame(2, $item->quantity);
        $this->assertTrue($item->user->is($item->user));
        $this->assertTrue($item->product->is($item->product));
        $this->assertTrue($item->user->cartItems->contains($item));
        $this->assertTrue($item->product->cartItems->contains($item));
        $this->assertSame(['quantity'], $item->getFillable());
    }

    public function test_database_rejects_non_positive_quantity(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        foreach ([0, -1] as $quantity) {
            try {
                DB::table('cart_items')->insert([
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->fail("Database accepted invalid quantity {$quantity}.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('cart_items_quantity_check', $exception->getMessage());
            }
        }
    }

    public function test_database_enforces_one_line_per_user_and_product(): void
    {
        $item = CartItem::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('cart_items')->insert([
            'user_id' => $item->user_id,
            'product_id' => $item->product_id,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_foreign_keys_restrict_user_and_product_deletion(): void
    {
        $item = CartItem::factory()->create();

        foreach ([$item->user, $item->product] as $model) {
            try {
                $model->delete();
                $this->fail('Database allowed deletion of a referenced cart owner or product.');
            } catch (QueryException) {
                $this->assertDatabaseHas('cart_items', ['id' => $item->id]);
            }
        }
    }

    public function test_subtotal_uses_integer_vnd_and_rejects_overflow(): void
    {
        $item = new CartItem(['quantity' => 3]);
        $this->assertSame(375000, $item->subtotalVnd(125000));

        $item->quantity = 2;
        $this->expectException(OverflowException::class);
        $item->subtotalVnd(PHP_INT_MAX);
    }

    public function test_sqlite_has_named_quantity_guards_and_expected_indexes(): void
    {
        $triggers = DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'cart_items'");
        $names = array_column(array_map(fn ($row) => (array) $row, $triggers), 'name');
        $this->assertContains('cart_items_quantity_check_insert', $names);
        $this->assertContains('cart_items_quantity_check_update', $names);

        $indexes = DB::select("PRAGMA index_list('cart_items')");
        $indexNames = array_column(array_map(fn ($row) => (array) $row, $indexes), 'name');
        $this->assertContains('cart_items_user_product_unique', $indexNames);
        $this->assertContains('cart_items_user_id_index', $indexNames);
    }

    public function test_migration_refuses_partial_existing_table_without_deleting_rows(): void
    {
        $item = CartItem::factory()->create();
        $migration = require database_path('migrations/2026_09_27_000003_create_cart_items_table.php');

        try {
            $migration->up();
            $this->fail('Migration accepted an existing cart_items table.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('partial migration state', $exception->getMessage());
            $this->assertDatabaseHas('cart_items', ['id' => $item->id]);
        }
    }

    public function test_isolated_down_up_preserves_users_and_products(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $migration = require database_path('migrations/2026_09_27_000003_create_cart_items_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('cart_items'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);

        $migration->up();
        $this->assertTrue(Schema::hasTable('cart_items'));
    }

    public function test_effective_price_defensively_uses_only_a_valid_sale_price(): void
    {
        $regular = Product::factory()->make(['price_vnd' => 200000, 'sale_price_vnd' => null]);
        $sale = Product::factory()->make(['price_vnd' => 200000, 'sale_price_vnd' => 150000]);
        $equal = Product::factory()->make(['price_vnd' => 200000, 'sale_price_vnd' => 200000]);

        $this->assertSame(200000, $regular->effectivePriceVnd());
        $this->assertFalse($regular->hasValidSalePrice());
        $this->assertSame(150000, $sale->effectivePriceVnd());
        $this->assertTrue($sale->hasValidSalePrice());
        $this->assertSame(200000, $equal->effectivePriceVnd());
        $this->assertFalse($equal->hasValidSalePrice());

        $invalid = Product::factory()->make(['price_vnd' => 0, 'sale_price_vnd' => null]);
        $this->expectException(UnexpectedValueException::class);
        $invalid->effectivePriceVnd();
    }
}
