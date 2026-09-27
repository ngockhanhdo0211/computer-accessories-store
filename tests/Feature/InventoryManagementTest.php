<?php

namespace Tests\Feature;

use App\Actions\ApproveStockAdjustment;
use App\Actions\DirectStockAdjustment;
use App\Actions\ImportStock;
use App\Actions\RecordDamagedStock;
use App\Actions\RejectStockAdjustment;
use App\Actions\RequestStockAdjustment;
use App\Models\InventoryAdjustmentRequest;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_updates_only_sellable_and_writes_one_idempotent_ledger_entry(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 2, 'damaged_quantity' => 3, 'sold_quantity' => 4]);
        $actor = User::factory()->employee()->create();
        $key = (string) Str::uuid();
        $first = app(ImportStock::class)->handle($product, $actor, 5, 'Nhập lô mới', $key);
        $second = app(ImportStock::class)->handle($product, $actor, 5, 'Nhập lô mới', $key);

        $this->assertTrue($first->is($second));
        $this->assertSame([7, 3, 4], array_values($product->refresh()->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity'])));
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_damaged_moves_physical_stock_without_changing_total_or_sold_and_is_idempotent(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 8, 'damaged_quantity' => 2, 'sold_quantity' => 4]);
        $actor = User::factory()->admin()->create();
        $key = (string) Str::uuid();
        app(RecordDamagedStock::class)->handle($product, $actor, 3, 'Kiểm tra thấy hỏng', $key);
        app(RecordDamagedStock::class)->handle($product, $actor, 3, 'Kiểm tra thấy hỏng', $key);

        $product->refresh();
        $this->assertSame(5, $product->sellable_quantity);
        $this->assertSame(5, $product->damaged_quantity);
        $this->assertSame(10, $product->sellable_quantity + $product->damaged_quantity);
        $this->assertSame(4, $product->sold_quantity);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_invalid_quantities_overflow_and_damaged_over_sellable_are_rejected(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 1]);
        $actor = User::factory()->employee()->create();
        foreach ([0, -1, 2_147_483_648] as $quantity) {
            try {
                app(ImportStock::class)->handle($product, $actor, $quantity, 'Reason', (string) Str::uuid());
                $this->fail('Expected validation error.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(ValidationException::class);
        app(RecordDamagedStock::class)->handle($product, $actor, 2, 'Too much', (string) Str::uuid());
    }

    public function test_projection_rolls_back_when_ledger_insert_fails(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 4]);
        $actor = User::factory()->employee()->create();
        DB::unprepared("CREATE TRIGGER inventory_fail_insert BEFORE INSERT ON inventory_transactions BEGIN SELECT RAISE(ABORT, 'forced'); END");
        try {
            app(ImportStock::class)->handle($product, $actor, 2, 'Rollback', (string) Str::uuid());
            $this->fail('Expected query exception.');
        } catch (QueryException) {
            $this->assertSame(4, $product->refresh()->sellable_quantity);
            $this->assertDatabaseCount('inventory_transactions', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_employee_request_is_pending_and_admin_approve_is_single_atomic_adjustment(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 5, 'damaged_quantity' => 1]);
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $request = app(RequestStockAdjustment::class)->handle($product, $employee, -2, 1, 'Đối soát', (string) Str::uuid());
        $this->assertTrue($request->isPending());
        $this->assertSame([5, 1], array_values($product->refresh()->only(['sellable_quantity', 'damaged_quantity'])));

        $first = app(ApproveStockAdjustment::class)->handle($request, $admin);
        $second = app(ApproveStockAdjustment::class)->handle($request, $admin);
        $this->assertTrue($first->is($second));
        $this->assertSame([3, 2], array_values($product->refresh()->only(['sellable_quantity', 'damaged_quantity'])));
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_reject_is_idempotent_and_never_changes_stock_or_creates_ledger(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 5]);
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $request = app(RequestStockAdjustment::class)->handle($product, $employee, -1, 0, 'Sai lệch', (string) Str::uuid());
        app(RejectStockAdjustment::class)->handle($request, $admin);
        app(RejectStockAdjustment::class)->handle($request, $admin);
        $this->assertSame(5, $product->refresh()->sellable_quantity);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->expectException(ValidationException::class);
        app(ApproveStockAdjustment::class)->handle($request, $admin);
    }

    public function test_approved_request_cannot_be_rejected_and_negative_result_is_blocked(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 1]);
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $tooLow = app(RequestStockAdjustment::class)->handle($product, $employee, -2, 0, 'Too low', (string) Str::uuid());
        try {
            app(ApproveStockAdjustment::class)->handle($tooLow, $admin);
            $this->fail('Expected negative stock validation.');
        } catch (ValidationException) {
            $this->assertTrue($tooLow->refresh()->isPending());
            $this->assertSame(1, $product->refresh()->sellable_quantity);
        }
        $valid = app(RequestStockAdjustment::class)->handle($product, $employee, 1, 0, 'Valid', (string) Str::uuid());
        app(ApproveStockAdjustment::class)->handle($valid, $admin);
        $this->expectException(ValidationException::class);
        app(RejectStockAdjustment::class)->handle($valid, $admin);
    }

    public function test_admin_direct_adjustment_creates_approved_request_ledger_and_audit(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 3, 'damaged_quantity' => 2, 'sold_quantity' => 7]);
        $admin = User::factory()->admin()->create();
        $key = (string) Str::uuid();
        $first = app(DirectStockAdjustment::class)->handle($product, $admin, 2, -1, 'Kiểm kê', $key);
        $second = app(DirectStockAdjustment::class)->handle($product, $admin, 2, -1, 'Kiểm kê', $key);
        $request = InventoryAdjustmentRequest::firstOrFail();

        $this->assertTrue($first->is($second));
        $this->assertTrue($request->isApproved());
        $this->assertSame($admin->id, $request->requested_by);
        $this->assertSame($admin->id, $request->reviewed_by);
        $this->assertSame([5, 1, 7], array_values($product->refresh()->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity'])));
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_direct_adjustment_idempotency_key_rejects_changed_scope_actor_or_payload(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 3]);
        $otherProduct = Product::factory()->create(['sellable_quantity' => 3]);
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $key = (string) Str::uuid();

        app(DirectStockAdjustment::class)->handle($product, $admin, 1, 0, 'Original direct adjustment', $key);

        foreach ([
            [$product, $admin, 2, 0, 'Original direct adjustment'],
            [$product, $admin, 1, 0, 'Changed reason'],
            [$otherProduct, $admin, 1, 0, 'Original direct adjustment'],
            [$product, $otherAdmin, 1, 0, 'Original direct adjustment'],
        ] as [$target, $actor, $sellableDelta, $damagedDelta, $reason]) {
            try {
                app(DirectStockAdjustment::class)->handle($target, $actor, $sellableDelta, $damagedDelta, $reason, $key);
                $this->fail('Expected direct adjustment idempotency mismatch.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('request_key', $exception->errors());
            }
        }

        $this->assertSame(4, $product->refresh()->sellable_quantity);
        $this->assertSame(3, $otherProduct->refresh()->sellable_quantity);
        $this->assertDatabaseCount('inventory_adjustment_requests', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_guest_customer_employee_admin_and_non_active_authorization_are_correct(): void
    {
        $product = Product::factory()->create();
        $this->get(route('inventory.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('inventory.index'))->assertForbidden();
        $this->actingAs(User::factory()->employee()->create())->get(route('inventory.index'))->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get(route('inventory.index'))->assertOk();
        $employee = User::factory()->employee()->create();
        $this->actingAs($employee)->get(route('inventory.adjustments.direct.form', $product))->assertForbidden();
        $this->actingAs($employee)->post(route('inventory.adjustments.direct', $product), [
            'sellable_delta' => 1,
            'damaged_delta' => 0,
            'reason' => 'Unauthorized direct adjustment',
            'request_key' => (string) Str::uuid(),
        ])->assertForbidden();
        foreach ([User::factory()->employee()->locked()->create(), User::factory()->admin()->inactive()->create()] as $user) {
            $this->actingAs($user)->get(route('inventory.index'))->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_http_validation_privilege_fields_and_system_types_are_not_exposed(): void
    {
        $product = Product::factory()->create();
        $employee = User::factory()->employee()->create();
        $payload = ['quantity' => '1.5', 'reason' => '   ', 'request_key' => (string) Str::uuid(),
            'type' => 'sale', 'actor_id' => 999, 'sellable_quantity' => 999];
        $this->actingAs($employee)->post(route('inventory.import', $product), $payload)
            ->assertSessionHasErrors(['quantity', 'reason']);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertSame(0, $product->refresh()->sellable_quantity);
        $this->from(route('inventory.import.form', $product))->post(route('inventory.import', $product), [
            'request_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors([
            'quantity' => 'Vui lòng nhập số lượng.',
            'reason' => 'Vui lòng nhập lý do.',
        ]);
        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn ($route) => in_array($route->getName(), ['inventory.sale', 'inventory.cancel_restore'], true)));
    }

    public function test_overview_search_filters_stable_pagination_history_and_ui_are_real_and_escaped(): void
    {
        $admin = User::factory()->admin()->create();
        $out = Product::factory()->create(['name' => '<script>Out</script>', 'sku' => 'OUT-1', 'sellable_quantity' => 0]);
        Product::factory()->create(['name' => 'Low', 'sku' => 'LOW-1', 'sellable_quantity' => 3, 'low_stock_threshold' => 5]);
        Product::factory()->create(['name' => 'Healthy', 'sku' => 'GOOD-1', 'sellable_quantity' => 9, 'low_stock_threshold' => 5]);
        app(ImportStock::class)->handle($out, $admin, 1, '<b>Imported</b>', (string) Str::uuid());

        $this->actingAs($admin)->get(route('inventory.index', ['search' => 'OUT-1']))->assertOk()
            ->assertSee('&lt;script&gt;Out&lt;/script&gt;', false)->assertDontSee('<script>Out</script>', false)
            ->assertSee('Lịch sử')->assertSee('Nhập kho')->assertSee('Đề nghị');
        $this->get(route('inventory.index', ['stock' => 'low']))->assertSee('Low')->assertDontSee('Healthy');
        $this->get(route('inventory.index', ['stock' => 'in_stock']))->assertSee('Healthy')->assertDontSee('Low');
        $this->get(route('inventory.history', $out))->assertOk()->assertSee('&lt;b&gt;Imported&lt;/b&gt;', false)
            ->assertDontSee('Sửa')->assertDontSee('Xóa');
    }

    public function test_forms_have_csrf_correct_methods_and_dashboard_catalog_reflect_real_stock(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['sellable_quantity' => 0]);
        $this->actingAs($admin)->get(route('inventory.import.form', $product))->assertOk()
            ->assertSee('name="_token"', false)->assertSee('name="request_key"', false);
        $this->get(route('inventory.adjustments.index'))->assertOk()->assertSee('Đề nghị điều chỉnh');
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Tồn kho')->assertSee('Hết hàng');
        $this->get(route('products.index'))->assertOk()->assertSee('Tạm hết hàng')->assertDontSee('Thêm vào giỏ');

        app(ImportStock::class)->handle($product, $admin, 2, 'Restock', (string) Str::uuid());
        $this->get(route('products.index'))->assertOk()->assertSee('Còn hàng')->assertDontSee('Thêm vào giỏ');
        $route = app('router')->getRoutes()->getByName('inventory.import');
        $this->assertSame(['POST'], $route->methods());
    }

    public function test_adjustment_http_uses_authenticated_requester_and_only_admin_can_decide(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 5]);
        $employee = User::factory()->employee()->create();
        $otherEmployee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $key = (string) Str::uuid();
        $this->actingAs($employee)->post(route('inventory.adjustments.store', $product), [
            'sellable_delta' => -1, 'damaged_delta' => 0, 'reason' => 'Đối soát thực tế', 'request_key' => $key,
            'requested_by' => $admin->id, 'reviewed_by' => $employee->id, 'approved_at' => now(),
        ])->assertRedirect(route('inventory.adjustments.index'));
        $request = InventoryAdjustmentRequest::firstOrFail();
        $this->assertSame($employee->id, $request->requested_by);
        $this->assertNull($request->reviewed_by);
        $this->actingAs($otherEmployee)->patch(route('inventory.adjustments.approve', $request))->assertForbidden();
        $this->actingAs($otherEmployee)->patch(route('inventory.adjustments.reject', $request))->assertForbidden();
        $this->actingAs($admin)->patch(route('inventory.adjustments.approve', $request), ['reviewed_by' => $employee->id])->assertRedirect();
        $this->assertSame($admin->id, $request->refresh()->reviewed_by);
        $this->assertDatabaseCount('inventory_transactions', 1);
    }

    public function test_employee_sees_only_own_requests_while_admin_sees_all(): void
    {
        $first = User::factory()->employee()->create();
        $second = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        InventoryAdjustmentRequest::factory()->for($product)->create(['requested_by' => $first->id, 'reason' => 'First private reason']);
        InventoryAdjustmentRequest::factory()->for($product)->create(['requested_by' => $second->id, 'reason' => 'Second private reason']);

        $this->actingAs($first)->get(route('inventory.adjustments.index'))->assertOk()
            ->assertSee('First private reason')->assertDontSee('Second private reason');
        $this->actingAs($admin)->get(route('inventory.adjustments.index'))->assertOk()
            ->assertSee('First private reason')->assertSee('Second private reason');
    }

    public function test_invalid_stored_role_or_status_fails_safely_on_inventory_routes(): void
    {
        $invalidRole = User::factory()->employee()->create();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        DB::table('users')->where('id', $invalidRole->id)->update(['role' => 'unknown']);
        DB::statement('PRAGMA ignore_check_constraints = OFF');
        $this->actingAs($invalidRole)->get(route('inventory.index'))->assertForbidden();

        $invalidStatus = User::factory()->employee()->create();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        DB::table('users')->where('id', $invalidStatus->id)->update(['status' => 'unknown']);
        DB::statement('PRAGMA ignore_check_constraints = OFF');
        $this->actingAs($invalidStatus)->get(route('inventory.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_distinct_serialized_writers_accumulate_without_lost_update_or_negative_stock(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 1]);
        $first = User::factory()->employee()->create();
        $second = User::factory()->employee()->create();
        app(ImportStock::class)->handle($product, $first, 2, 'First import', (string) Str::uuid());
        app(ImportStock::class)->handle($product, $second, 3, 'Second import', (string) Str::uuid());
        app(RecordDamagedStock::class)->handle($product, $first, 6, 'All damaged', (string) Str::uuid());
        $this->assertSame([0, 6], array_values($product->refresh()->only(['sellable_quantity', 'damaged_quantity'])));
        $this->assertDatabaseCount('inventory_transactions', 3);
        $this->expectException(ValidationException::class);
        app(RecordDamagedStock::class)->handle($product, $second, 1, 'Would be negative', (string) Str::uuid());
    }

    public function test_overview_and_history_have_bounded_queries_and_out_of_range_page_redirects(): void
    {
        $admin = User::factory()->admin()->create();
        $products = Product::factory()->count(21)->create();
        foreach ($products->take(10) as $product) {
            app(ImportStock::class)->handle($product, $admin, 1, 'Query test', (string) Str::uuid());
        }
        $this->actingAs($admin);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('inventory.index'))->assertOk();
        $this->assertLessThanOrEqual(5, count(DB::getQueryLog()));
        DB::flushQueryLog();
        $this->get(route('inventory.history', $products->first()))->assertOk();
        $this->assertLessThanOrEqual(6, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->get(route('inventory.index', ['page' => 999]))->assertRedirect(route('inventory.index', ['sort' => 'name', 'page' => 2]));
    }

    public function test_processed_request_and_idempotency_payload_are_immutable(): void
    {
        $product = Product::factory()->create();
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $key = (string) Str::uuid();
        $request = app(RequestStockAdjustment::class)->handle($product, $employee, 1, 0, 'Original', $key);
        try {
            app(RequestStockAdjustment::class)->handle($product, $employee, 2, 0, 'Changed', $key);
            $this->fail('Expected idempotency payload mismatch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_key', $exception->errors());
        }
        app(ApproveStockAdjustment::class)->handle($request, $admin);
        $this->expectException(\LogicException::class);
        $request->refresh()->forceFill(['reason' => 'Changed after approval'])->save();
    }

    public function test_idempotency_keys_are_bound_to_scope_actor_and_payload(): void
    {
        $firstProduct = Product::factory()->create(['sellable_quantity' => 3]);
        $secondProduct = Product::factory()->create(['sellable_quantity' => 3]);
        $employee = User::factory()->employee()->create();
        $otherEmployee = User::factory()->employee()->create();
        $key = (string) Str::uuid();

        app(ImportStock::class)->handle($firstProduct, $employee, 1, 'Original import', $key);
        foreach ([[2, 'Original import'], [1, 'Changed reason']] as [$quantity, $reason]) {
            try {
                app(ImportStock::class)->handle($firstProduct, $employee, $quantity, $reason, $key);
                $this->fail('Expected import idempotency mismatch.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('request_key', $exception->errors());
            }
        }
        try {
            app(ImportStock::class)->handle($firstProduct, $otherEmployee, 1, 'Original import', $key);
            $this->fail('Expected actor idempotency mismatch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_key', $exception->errors());
        }

        app(ImportStock::class)->handle($secondProduct, $employee, 1, 'Second product', $key);
        app(RecordDamagedStock::class)->handle($firstProduct, $employee, 1, 'Different action', $key);
        $this->assertDatabaseCount('inventory_transactions', 3);
        $this->assertDatabaseCount('audit_logs', 3);

        $requestKey = (string) Str::uuid();
        app(RequestStockAdjustment::class)->handle($firstProduct, $employee, 1, 0, 'Original request', $requestKey);
        foreach ([[$secondProduct, 1, 'Original request'], [$firstProduct, 2, 'Original request'], [$firstProduct, 1, 'Changed reason']] as [$product, $delta, $reason]) {
            try {
                app(RequestStockAdjustment::class)->handle($product, $employee, $delta, 0, $reason, $requestKey);
                $this->fail('Expected request idempotency mismatch.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('request_key', $exception->errors());
            }
        }
    }

    public function test_audit_is_atomic_complete_and_contains_only_business_fields(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 4]);
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();

        app(ImportStock::class)->handle($product, $employee, 1, 'Import audit', (string) Str::uuid());
        app(RecordDamagedStock::class)->handle($product, $employee, 1, 'Damaged audit', (string) Str::uuid());
        $approved = app(RequestStockAdjustment::class)->handle($product, $employee, 1, 0, 'Approve audit', (string) Str::uuid());
        app(ApproveStockAdjustment::class)->handle($approved, $admin);
        $rejected = app(RequestStockAdjustment::class)->handle($product, $employee, 1, 0, 'Reject audit', (string) Str::uuid());
        app(RejectStockAdjustment::class)->handle($rejected, $admin);
        app(DirectStockAdjustment::class)->handle($product, $admin, 1, 0, 'Direct audit', (string) Str::uuid());

        $this->assertSame([
            'inventory.stock.imported',
            'inventory.stock.damaged',
            'inventory.adjustment.requested',
            'inventory.adjustment.approved',
            'inventory.adjustment.requested',
            'inventory.adjustment.rejected',
            'inventory.adjustment.direct',
        ], DB::table('audit_logs')->orderBy('id')->pluck('action')->all());
        $payload = DB::table('audit_logs')->selectRaw('before_json || after_json AS payload')->pluck('payload')->implode(' ');
        $this->assertDoesNotMatchRegularExpression('/password|session|csrf|token/i', $payload);
    }

    public function test_audit_failure_rolls_back_projection_and_ledger(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 2]);
        $employee = User::factory()->employee()->create();
        DB::unprepared("CREATE TRIGGER audit_fail_insert BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'forced audit failure'); END");

        try {
            app(ImportStock::class)->handle($product, $employee, 1, 'Must rollback', (string) Str::uuid());
            $this->fail('Expected audit insert failure.');
        } catch (QueryException) {
            $this->assertSame(2, $product->refresh()->sellable_quantity);
            $this->assertDatabaseCount('inventory_transactions', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_approval_revalidates_current_stock_and_projection_overflow_is_blocked(): void
    {
        $product = Product::factory()->create(['sellable_quantity' => 3]);
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $request = app(RequestStockAdjustment::class)->handle($product, $employee, -2, 0, 'Stock changed later', (string) Str::uuid());
        app(RecordDamagedStock::class)->handle($product, $employee, 2, 'Reduce before approval', (string) Str::uuid());

        try {
            app(ApproveStockAdjustment::class)->handle($request, $admin);
            $this->fail('Expected approval to revalidate current stock.');
        } catch (ValidationException) {
            $this->assertTrue($request->refresh()->isPending());
            $this->assertSame(1, $product->refresh()->sellable_quantity);
        }

        $maximum = Product::factory()->create(['sellable_quantity' => 4_294_967_295]);
        $this->expectException(ValidationException::class);
        app(ImportStock::class)->handle($maximum, $employee, 1, 'Overflow', (string) Str::uuid());
    }

    public function test_inventory_search_treats_wildcards_as_literals_and_history_uses_id_tie_breaker(): void
    {
        $admin = User::factory()->admin()->create();
        $literal = Product::factory()->create(['name' => 'Literal %_! marker', 'sku' => 'LITERAL-1']);
        Product::factory()->create(['name' => 'Ordinary marker', 'sku' => 'OTHER-1']);
        $this->actingAs($admin)->get(route('inventory.index', ['search' => '%_!']))
            ->assertOk()->assertSee('LITERAL-1')->assertDontSee('OTHER-1');

        $time = now()->startOfSecond();
        DB::table('inventory_transactions')->insert([
            ['product_id' => $literal->id, 'type' => 'import', 'sellable_delta' => 1, 'damaged_delta' => 0, 'source_key' => (string) Str::uuid(), 'actor_id' => $admin->id, 'reason' => 'Older ID', 'created_at' => $time],
            ['product_id' => $literal->id, 'type' => 'import', 'sellable_delta' => 1, 'damaged_delta' => 0, 'source_key' => (string) Str::uuid(), 'actor_id' => $admin->id, 'reason' => 'Newer ID', 'created_at' => $time],
        ]);
        $this->get(route('inventory.history', $literal))->assertOk()->assertSeeInOrder(['Newer ID', 'Older ID']);
    }
}
