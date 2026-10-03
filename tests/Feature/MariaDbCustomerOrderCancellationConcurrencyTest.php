<?php

namespace Tests\Feature;

use App\Actions\SubmitOrderCancellationRequest;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderCancellationRequestStatus;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbCustomerOrderCancellationConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (env('RUN_MARIADB_CUSTOMER_CANCEL_QA') !== '1') {
            $this->markTestSkipped('Run with the isolated MariaDB Customer cancellation QA runner.');
        }
        if (DB::getDriverName() !== 'mysql' || ! str_contains((string) DB::getDatabaseName(), '_customer_cancel_qa_')) {
            $this->fail('Customer cancellation concurrency QA requires the isolated marker database.');
        }
    }

    public function test_customer_double_submit_and_staff_review_races_are_exactly_once(): void
    {
        [$order, $customer, $item] = $this->order();
        $requestKey = (string) Str::uuid();
        $submit = ['operation' => 'submit', 'order_id' => $order->id, 'actor_id' => $customer->id,
            'key' => $requestKey, 'reason' => 'MariaDB double submit'];
        $this->assertSame([0, 0], $this->codes($this->runTwo($submit, $submit)));
        $request = OrderCancellationRequest::query()->where('order_id', $order->id)->sole();
        $this->assertSame(1, AuditLog::query()->where('action', 'order.cancellation_requested')->where('subject_id', $request->id)->count());

        [$conflictOrder, $conflictCustomer] = $this->order();
        $conflictKey = (string) Str::uuid();
        $this->assertSame([0, 2], $this->codes($this->runTwo(
            ['operation' => 'submit', 'order_id' => $conflictOrder->id, 'actor_id' => $conflictCustomer->id,
                'key' => $conflictKey, 'reason' => 'Payload A'],
            ['operation' => 'submit', 'order_id' => $conflictOrder->id, 'actor_id' => $conflictCustomer->id,
                'key' => $conflictKey, 'reason' => 'Payload B'],
        )));
        $this->assertSame(1, OrderCancellationRequest::query()->where('order_id', $conflictOrder->id)->count());

        $admin = User::factory()->admin()->create();
        $eventKey = (string) Str::uuid();
        $approve = ['operation' => 'review', 'request_id' => $request->id, 'actor_id' => $admin->id,
            'key' => $eventKey, 'decision' => 'approved', 'note' => null];
        $this->assertSame([0, 0], $this->codes($this->runTwo($approve, $approve)));
        $this->assertSame(OrderCancellationRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(1, InventoryTransaction::query()->where('type', InventoryTransactionType::CancelRestore)->where('order_item_id', $item->id)->count());
        $this->assertSame(1, OrderStatusHistory::query()->where('event_key', $eventKey)->count());
        $this->assertSame(1, AuditLog::query()->where('request_id', $eventKey)->count());

        [$competingOrder, $competingCustomer, $competingItem] = $this->order();
        $competingRequest = app(SubmitOrderCancellationRequest::class)->handle($competingCustomer, $competingOrder->order_code, (string) Str::uuid(), 'Competing decisions');
        $this->assertSame([0, 2], $this->codes($this->runTwo(
            ['operation' => 'review', 'request_id' => $competingRequest->id, 'actor_id' => $admin->id,
                'key' => (string) Str::uuid(), 'decision' => 'approved', 'note' => null],
            ['operation' => 'review', 'request_id' => $competingRequest->id, 'actor_id' => $admin->id,
                'key' => (string) Str::uuid(), 'decision' => 'rejected', 'note' => 'Từ chối cạnh tranh'],
        )));
        $terminal = $competingRequest->fresh()->status;
        $this->assertContains($terminal, [OrderCancellationRequestStatus::Approved, OrderCancellationRequestStatus::Rejected]);
        $this->assertSame($terminal === OrderCancellationRequestStatus::Approved ? 1 : 0,
            InventoryTransaction::query()->where('type', InventoryTransactionType::CancelRestore)->where('order_item_id', $competingItem->id)->count());
    }

    public function test_mariadb_metadata_and_direct_sql_guards_preserve_request_and_ledger_invariants(): void
    {
        $metadata = DB::selectOne('SELECT VERSION() AS version, @@innodb_force_recovery AS recovery');
        $this->assertStringContainsString('10.4.32-MariaDB', $metadata->version);
        $this->assertSame(0, (int) $metadata->recovery);
        foreach (DB::select('CHECK TABLE order_cancellation_requests, inventory_transactions, refunds') as $check) {
            $this->assertSame('OK', $check->Msg_text);
        }
        $this->assertSame(1, DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'order_cancellation_requests')->where('INDEX_NAME', 'order_cancellation_requests_order_unique')->count());
        $this->assertSame(1, DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', 'inventory_transactions_terminal_insert_check')->count());

        [$order, $customer, $item, $product] = $this->order();
        $request = app(SubmitOrderCancellationRequest::class)->handle($customer, $order->order_code, (string) Str::uuid(), 'Direct SQL guard');
        try {
            DB::table('inventory_transactions')->insert([
                'product_id' => $product->id, 'type' => 'cancel_restore', 'sellable_delta' => $item->quantity,
                'damaged_delta' => 0, 'source_key' => 'pending-customer-cancel', 'adjustment_request_id' => null,
                'order_item_id' => $item->id, 'return_inspection_id' => null,
                'order_cancellation_request_id' => $request->id, 'actor_id' => null, 'reason' => null, 'created_at' => now(),
            ]);
            $this->fail('MariaDB accepted a ledger for a pending cancellation request.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('inventory_transactions', ['source_key' => 'pending-customer-cancel']);
        }
        try {
            DB::table('order_cancellation_requests')->where('id', $request->id)->delete();
            $this->fail('MariaDB deleted cancellation evidence.');
        } catch (QueryException) {
            $this->assertDatabaseHas('order_cancellation_requests', ['id' => $request->id]);
        }
    }

    /** @return array{Order, User, OrderItem, Product} */
    private function order(): array
    {
        $customer = User::factory()->create();
        $order = Order::factory()->for($customer, 'customer')->create();
        $product = Product::factory()->create(['sellable_quantity' => 5, 'damaged_quantity' => 1, 'sold_quantity' => 4]);
        $item = OrderItem::factory()->for($order)->for($product)->create();
        OrderStatusHistory::factory()->for($order)->create(['from_status' => null, 'to_status' => OrderStatus::Placed]);

        return [$order, $customer, $item, $product];
    }

    private function workerCode(): string
    {
        return <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$deadline = microtime(true) + 10;
while (! file_exists($argv[2]) && microtime(true) < $deadline) { usleep(10000); }
if (! file_exists($argv[2])) { fwrite(STDERR, 'barrier timeout'); exit(4); }
try {
    $actor = App\Models\User::query()->findOrFail($payload['actor_id']);
    if ($payload['operation'] === 'submit') {
        $order = App\Models\Order::query()->findOrFail($payload['order_id']);
        app(App\Actions\SubmitOrderCancellationRequest::class)->handle($actor, $order->order_code, $payload['key'], $payload['reason']);
    } else {
        $request = App\Models\OrderCancellationRequest::query()->findOrFail($payload['request_id']);
        app(App\Actions\ReviewOrderCancellationRequest::class)->handle($request, $actor, $payload['key'], $payload['decision'], $payload['note']);
    }
    exit(0);
} catch (Illuminate\Validation\ValidationException $exception) {
    fwrite(STDERR, 'domain rejection'); exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()); exit(3);
}
PHP;
    }

    /** @return list<Process> */
    private function runTwo(array $first, array $second): array
    {
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'customer-cancel-'.Str::uuid();
        $worker = $this->workerCode();
        $processes = array_map(function (array $payload) use ($barrier, $worker): Process {
            $process = new Process([PHP_BINARY, '-r', $worker, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $barrier], base_path());
            $process->setTimeout(30);
            $process->start();

            return $process;
        }, [$first, $second]);

        try {
            usleep(100000);
            file_put_contents($barrier, 'go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertNotSame(4, $process->getExitCode(), $process->getErrorOutput());
                $this->assertNotSame(3, $process->getExitCode(), $process->getErrorOutput());
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if (file_exists($barrier)) {
                unlink($barrier);
            }
        }

        return $processes;
    }

    private function codes(array $processes): array
    {
        $codes = array_map(fn (Process $process): int => $process->getExitCode() ?? -1, $processes);
        sort($codes);

        return $codes;
    }
}
