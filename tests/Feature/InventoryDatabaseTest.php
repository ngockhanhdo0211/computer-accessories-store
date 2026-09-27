<?php

namespace Tests\Feature;

use App\Actions\ApproveStockAdjustment;
use App\Actions\ImportStock;
use App\Enums\InventoryTransactionType;
use App\Models\AuditLog;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class InventoryDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_schema_indexes_casts_relationships_and_factories_are_valid(): void
    {
        foreach (['audit_logs', 'inventory_adjustment_requests', 'inventory_transactions'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $product = Product::factory()->create(['sellable_quantity' => 1]);
        $actor = User::factory()->employee()->create();
        $request = InventoryAdjustmentRequest::factory()->for($product)->create(['requested_by' => $actor->id]);
        $transaction = InventoryTransaction::factory()->for($product)->create(['actor_id' => $actor->id]);

        $this->assertSame(InventoryTransactionType::Import, $transaction->type);
        $this->assertTrue($product->is($transaction->product));
        $this->assertTrue($actor->is($transaction->actor));
        $this->assertTrue($product->is($request->product));
        $this->assertTrue($actor->is($request->requester));
        $this->assertTrue($request->isPending());
        $this->assertSame(1, $product->inventoryTransactions()->count());
        $this->assertSame(1, $product->inventoryAdjustmentRequests()->count());
    }

    public function test_database_rejects_unknown_type_zero_deltas_and_invalid_adjustment_state(): void
    {
        $product = Product::factory()->create();
        $actor = User::factory()->employee()->create();
        foreach ([['type' => 'unknown', 'sellable_delta' => 1], ['type' => 'import', 'sellable_delta' => 0]] as $case) {
            try {
                DB::table('inventory_transactions')->insert([
                    'product_id' => $product->id, 'type' => $case['type'], 'sellable_delta' => $case['sellable_delta'],
                    'damaged_delta' => 0, 'source_key' => (string) Str::uuid(), 'actor_id' => $actor->id, 'created_at' => now(),
                ]);
                $this->fail('Expected inventory transaction constraint failure.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $this->expectException(QueryException::class);
        DB::table('inventory_adjustment_requests')->insert([
            'product_id' => $product->id, 'requested_by' => $actor->id, 'request_key' => (string) Str::uuid(),
            'sellable_delta' => 0, 'damaged_delta' => 0, 'reason' => 'Invalid',
            'approved_at' => now(), 'rejected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_foreign_keys_delete_policies_and_uniques_protect_inventory_history(): void
    {
        $product = Product::factory()->create();
        $actor = User::factory()->employee()->create();
        $key = (string) Str::uuid();
        InventoryTransaction::factory()->for($product)->create(['actor_id' => $actor->id, 'source_key' => $key]);

        try {
            $product->delete();
            $this->fail('Expected Product delete restriction.');
        } catch (QueryException) {
            $this->assertDatabaseHas('products', ['id' => $product->id]);
        }

        $actor->delete();
        $this->assertDatabaseHas('inventory_transactions', ['product_id' => $product->id, 'actor_id' => null]);

        $this->expectException(QueryException::class);
        DB::table('inventory_transactions')->insert([
            'product_id' => $product->id, 'type' => 'import', 'sellable_delta' => 1, 'damaged_delta' => 0,
            'source_key' => $key, 'created_at' => now(),
        ]);
    }

    public function test_adjustment_request_has_unique_key_and_one_to_one_transaction(): void
    {
        $request = InventoryAdjustmentRequest::factory()->create();
        $transaction = InventoryTransaction::factory()->for($request->product)->create([
            'type' => InventoryTransactionType::ManualAdjustment, 'adjustment_request_id' => $request->id,
            'source_key' => 'adjustment:'.$request->id,
        ]);
        $this->assertTrue($transaction->is($request->transaction));

        $this->expectException(QueryException::class);
        InventoryTransaction::factory()->for($request->product)->create([
            'type' => InventoryTransactionType::ManualAdjustment, 'adjustment_request_id' => $request->id,
            'source_key' => 'another-key',
        ]);
    }

    public function test_ledger_is_immutable_through_model_and_database(): void
    {
        $transaction = InventoryTransaction::factory()->create();
        $originalReason = $transaction->reason;
        try {
            $transaction->forceFill(['reason' => 'Changed'])->save();
            $this->fail('Expected application immutability guard.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        try {
            DB::table('inventory_transactions')->where('id', $transaction->id)->update(['reason' => 'Changed']);
            $this->fail('Expected database immutability guard.');
        } catch (QueryException) {
            $this->assertDatabaseHas('inventory_transactions', ['id' => $transaction->id, 'reason' => $originalReason]);
        }
        try {
            DB::table('inventory_transactions')->where('id', $transaction->id)->delete();
            $this->fail('Expected database delete guard.');
        } catch (QueryException) {
            $this->assertDatabaseHas('inventory_transactions', ['id' => $transaction->id, 'reason' => $originalReason]);
        }
        $this->assertSame(1, InventoryTransaction::query()->where('product_id', $transaction->product_id)->count());
    }

    public function test_partial_migration_guard_refuses_existing_tables(): void
    {
        foreach (['2026_09_27_000000_create_audit_logs_table.php', '2026_09_27_000001_create_inventory_adjustment_requests_table.php', '2026_09_27_000002_create_inventory_transactions_table.php'] as $file) {
            $migration = require database_path('migrations/'.$file);
            try {
                $migration->up();
                $this->fail('Expected partial-state guard.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('already exists', $exception->getMessage());
            }
        }
    }

    public function test_inventory_migrations_do_not_change_existing_product_projections(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 7, 'damaged_quantity' => 2, 'sold_quantity' => 3, 'low_stock_threshold' => 9]);
        $this->assertSame([7, 2, 3, 9], array_values($product->refresh()->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity', 'low_stock_threshold'])));
    }

    public function test_adjustment_state_check_and_database_immutability_are_strict(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 2]);
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $base = [
            'product_id' => $product->id,
            'requested_by' => $employee->id,
            'sellable_delta' => 1,
            'damaged_delta' => 0,
            'reason' => 'State check',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $invalidStates = [
            ['reviewed_by' => $admin->id],
            ['approved_at' => now()],
            ['rejected_at' => now()],
            ['reviewed_by' => $admin->id, 'approved_at' => now(), 'rejected_at' => now()],
        ];
        foreach ($invalidStates as $state) {
            try {
                DB::table('inventory_adjustment_requests')->insert([...$base, ...$state, 'request_key' => (string) Str::uuid()]);
                $this->fail('Expected invalid adjustment state to be rejected.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $request = InventoryAdjustmentRequest::factory()->for($product)->create(['requested_by' => $employee->id]);
        $originalReason = $request->reason;
        try {
            DB::table('inventory_adjustment_requests')->where('id', $request->id)->update(['reason' => 'Changed pending request']);
            $this->fail('Expected pending request mutation to be rejected.');
        } catch (QueryException) {
            $this->assertSame($originalReason, $request->refresh()->reason);
        }
        app(ApproveStockAdjustment::class)->handle($request, $admin);
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('inventory_adjustment_requests')->where('id', $request->id);
                $operation === 'update' ? $query->update(['reason' => 'Changed']) : $query->delete();
                $this->fail('Expected processed request immutability guard.');
            } catch (QueryException) {
                $this->assertDatabaseHas('inventory_adjustment_requests', ['id' => $request->id, 'reason' => $originalReason]);
            }
        }
        try {
            $admin->delete();
            $this->fail('Expected reviewer delete restriction.');
        } catch (QueryException) {
            $this->assertDatabaseHas('users', ['id' => $admin->id]);
        }
    }

    public function test_audit_log_is_immutable_and_actor_set_null_is_the_only_database_update(): void
    {
        $product = Product::factory()->create();
        $actor = User::factory()->employee()->create();
        app(ImportStock::class)->handle($product, $actor, 1, 'Audit immutable', (string) Str::uuid());
        $audit = AuditLog::query()->firstOrFail();
        $transaction = InventoryTransaction::query()->firstOrFail();

        try {
            $audit->forceFill(['action' => 'changed'])->save();
            $this->fail('Expected model audit immutability guard.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('audit_logs')->where('id', $audit->id);
                $operation === 'update' ? $query->update(['action' => 'changed']) : $query->delete();
                $this->fail('Expected database audit immutability guard.');
            } catch (QueryException) {
                $this->assertDatabaseHas('audit_logs', ['id' => $audit->id, 'action' => 'inventory.stock.imported']);
            }
        }

        $actor->delete();
        $this->assertDatabaseHas('audit_logs', ['id' => $audit->id, 'actor_id' => null]);
        $this->assertDatabaseHas('inventory_transactions', ['id' => $transaction->id, 'actor_id' => null]);
    }
}
