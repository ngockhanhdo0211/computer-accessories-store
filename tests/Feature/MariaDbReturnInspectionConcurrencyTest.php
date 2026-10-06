<?php

namespace Tests\Feature;

use App\Actions\ReceiveReturnInspection;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnInspection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbReturnInspectionConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('RUN_MARIADB_RETURN_INSPECTION_QA') !== '1') {
            $this->markTestSkipped('Run with the isolated MariaDB Return Inspection QA runner.');
        }
        if (DB::getDriverName() !== 'mysql'
            || ! str_contains((string) DB::getDatabaseName(), '_return_inspection_qa_')) {
            $this->fail('Return Inspection concurrency QA requires the isolated marker database.');
        }
    }

    public function test_mariadb_metadata_checks_and_triggers_protect_inspections(): void
    {
        $constraints = collect(DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'return_inspections'
            SQL))->pluck('CONSTRAINT_NAME')->all();
        $triggers = collect(DB::select(<<<'SQL'
            SELECT TRIGGER_NAME
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'return_inspections'
            SQL))->pluck('TRIGGER_NAME')->all();

        foreach ([
            'return_inspections_order_item_id_unique',
            'return_inspections_shape_check',
            'return_inspections_sellable_quantity_check',
            'return_inspections_damaged_quantity_check',
            'return_inspections_time_check',
            'return_inspections_receive_event_pair_check',
            'return_inspections_complete_event_pair_check',
            'return_inspections_receive_event_format_check',
            'return_inspections_complete_event_format_check',
            'return_inspections_receive_fingerprint_check',
            'return_inspections_complete_fingerprint_check',
        ] as $constraint) {
            $this->assertContains($constraint, $constraints);
        }
        foreach ([
            'return_inspections_insert_guard',
            'return_inspections_update_guard',
            'return_inspections_delete_guard',
        ] as $trigger) {
            $this->assertContains($trigger, $triggers);
        }
        foreach (['return_inspections', 'inventory_transactions'] as $table) {
            $status = DB::selectOne('CHECK TABLE `'.$table.'`');
            $this->assertSame('OK', strtoupper((string) $status->Msg_text));
        }

        [$order, $item] = $this->orderWithItem();
        $admin = User::factory()->admin()->create();
        $invalidItem = OrderItem::factory()->for($order)->create();
        $inspection = app(ReceiveReturnInspection::class)->handle(
            $order->order_code, $item->id, $admin, (string) Str::uuid(),
        );

        $this->assertQueryFails(fn () => DB::table('return_inspections')->where('id', $inspection->id)->update([
            'note' => 'Không được sửa pending',
        ]));
        $this->assertQueryFails(fn () => DB::table('return_inspections')->where('id', $inspection->id)->update([
            'inspected_by' => $admin->id,
            'inspected_at' => '2026-10-01 02:00:00.000000',
            'sellable_quantity' => 1,
            'damaged_quantity' => 0,
        ]));
        $this->assertQueryFails(fn () => DB::table('return_inspections')->where('id', $inspection->id)->delete());
        $this->assertQueryFails(fn () => DB::table('return_inspections')->insert([
            'order_item_id' => $invalidItem->id,
            'received_by' => $admin->id,
            'received_at' => now(),
            'inspected_by' => $admin->id,
            'inspected_at' => now(),
            'sellable_quantity' => 1,
            'damaged_quantity' => null,
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->assertQueryFails(fn () => DB::table('return_inspections')->insert([
            'order_item_id' => $invalidItem->id,
            'received_by' => $admin->id,
            'received_at' => now(),
            'inspected_by' => null,
            'inspected_at' => null,
            'sellable_quantity' => null,
            'damaged_quantity' => null,
            'note' => null,
            'receive_event_key' => 'NOT-A-UUID',
            'receive_fingerprint' => hash('sha256', 'invalid'),
            'complete_event_key' => null,
            'complete_fingerprint' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->assertQueryFails(fn () => DB::table('return_inspections')->insert([
            'order_item_id' => $invalidItem->id,
            'received_by' => $admin->id,
            'received_at' => now(),
            'inspected_by' => $admin->id,
            'inspected_at' => now(),
            'sellable_quantity' => 2,
            'damaged_quantity' => 0,
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_concurrent_receive_creates_one_idempotent_inspection(): void
    {
        [$order, $item] = $this->orderWithItem();
        $admin = User::factory()->admin()->create();
        $at = '2026-10-01T01:00:00.000000Z';

        $processes = $this->runTwo('receive', $order, $item, $admin, $at, 0, 0, 'Nhận');

        $this->assertSame([0, 0], $this->exitCodes($processes), $this->errors($processes));
        $this->assertSame(1, ReturnInspection::query()->where('order_item_id', $item->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'return_inspection.received')->where('subject_id', ReturnInspection::query()->where('order_item_id', $item->id)->value('id'))->count());
    }

    public function test_concurrent_complete_applies_one_completion(): void
    {
        [$order, $item] = $this->orderWithItem(quantity: 3);
        $admin = User::factory()->admin()->create();
        app(ReceiveReturnInspection::class)->handle($order->order_code, $item->id, $admin, (string) Str::uuid(), 'Nhận');

        $processes = $this->runTwo('complete', $order, $item, $admin, '2026-10-01T02:00:00.000000Z', 2, 1, 'Hoàn tất');

        $this->assertSame([0, 0], $this->exitCodes($processes), $this->errors($processes));
        $inspection = ReturnInspection::query()->where('order_item_id', $item->id)->firstOrFail();
        $this->assertTrue($inspection->isCompleted());
        $this->assertSame(2, $inspection->sellable_quantity);
        $this->assertSame(1, $inspection->damaged_quantity);
        $this->assertSame(1, AuditLog::query()->where('action', 'return_inspection.completed')->where('subject_id', $inspection->id)->count());
    }

    public function test_concurrent_conflicting_receive_has_one_winner_and_one_audit(): void
    {
        [$order, $item] = $this->orderWithItem();
        $admin = User::factory()->admin()->create();
        $tasks = [
            $this->task('receive', $item, note: 'A'),
            $this->task('receive', $item, note: 'B'),
        ];

        $processes = $this->runTasks($order, $admin, $tasks);

        $this->assertSame([0, 3], $this->exitCodes($processes), $this->errors($processes));
        $inspection = ReturnInspection::query()->where('order_item_id', $item->id)->firstOrFail();
        $this->assertContains($inspection->note, ['A', 'B']);
        $this->assertSame(1, AuditLog::query()->where('action', 'return_inspection.received')->where('subject_id', $inspection->id)->count());
    }

    public function test_concurrent_conflicting_complete_has_one_winner_and_one_audit(): void
    {
        [$order, $item] = $this->orderWithItem(quantity: 3);
        $admin = User::factory()->admin()->create();
        app(ReceiveReturnInspection::class)->handle(
            $order->order_code, $item->id, $admin, (string) Str::uuid(),
        );
        $tasks = [
            $this->task('complete', $item, 2, 1, 'A'),
            $this->task('complete', $item, 1, 2, 'B'),
        ];

        $processes = $this->runTasks($order, $admin, $tasks);

        $this->assertSame([0, 3], $this->exitCodes($processes), $this->errors($processes));
        $inspection = ReturnInspection::query()->where('order_item_id', $item->id)->firstOrFail();
        $this->assertContains([$inspection->sellable_quantity, $inspection->damaged_quantity], [[2, 1], [1, 2]]);
        $this->assertSame(1, AuditLog::query()->where('action', 'return_inspection.completed')->where('subject_id', $inspection->id)->count());
    }

    public function test_concurrent_receive_and_complete_remain_consistent(): void
    {
        [$order, $item] = $this->orderWithItem();
        $admin = User::factory()->admin()->create();
        $tasks = [
            $this->task('receive', $item, note: 'Nhận'),
            $this->task('complete', $item, 2, 0, 'Nhận'),
        ];

        $processes = $this->runTasks($order, $admin, $tasks);

        $this->assertContains($this->exitCodes($processes), [[0, 0], [0, 3]]);
        $inspection = ReturnInspection::query()->where('order_item_id', $item->id)->firstOrFail();
        $this->assertSame(1, AuditLog::query()->where('action', 'return_inspection.received')->where('subject_id', $inspection->id)->count());
        $this->assertSame(
            $inspection->isCompleted() ? 1 : 0,
            AuditLog::query()->where('action', 'return_inspection.completed')->where('subject_id', $inspection->id)->count(),
        );
    }

    public function test_concurrent_different_items_lock_in_id_order_without_deadlock(): void
    {
        [$order, $first] = $this->orderWithItem();
        $second = OrderItem::factory()->for($order)->create();
        $admin = User::factory()->admin()->create();
        $tasks = [
            $this->task('receive', $second, note: 'Hai'),
            $this->task('receive', $first, note: 'Một'),
        ];

        $processes = $this->runTasks($order, $admin, $tasks);

        $this->assertSame([0, 0], $this->exitCodes($processes), $this->errors($processes));
        $inspections = ReturnInspection::query()->whereIn('order_item_id', [$first->id, $second->id])->get();
        $this->assertCount(2, $inspections);
        $this->assertSame(2, AuditLog::query()->where('action', 'return_inspection.received')->whereIn('subject_id', $inspections->pluck('id'))->count());
    }

    /** @return array{Order, OrderItem} */
    private function orderWithItem(int $quantity = 2): array
    {
        $order = Order::factory()->create(['status' => OrderStatus::Placed]);
        $item = OrderItem::factory()->for($order)->create([
            'quantity' => $quantity,
            'line_subtotal_vnd' => 100_000 * $quantity,
            'line_total_vnd' => 100_000 * $quantity,
        ]);

        return [$order, $item];
    }

    /** @return array{operation: string, item_id: int, at: string, event_key: string, sellable: int, damaged: int, note: string} */
    private function task(
        string $operation,
        OrderItem $item,
        int $sellable = 0,
        int $damaged = 0,
        string $note = 'Nhận',
    ): array {
        return [
            'operation' => $operation,
            'item_id' => $item->id,
            'at' => '2026-10-01T01:00:00.000000Z',
            'event_key' => (string) Str::uuid(),
            'sellable' => $sellable,
            'damaged' => $damaged,
            'note' => $note,
        ];
    }

    /** @return list<Process> */
    private function runTwo(
        string $operation,
        Order $order,
        OrderItem $item,
        User $actor,
        string $at,
        int $sellable,
        int $damaged,
        string $note,
    ): array {
        $task = $this->task($operation, $item, $sellable, $damaged, $note);
        $task['at'] = $at;

        return $this->runTasks($order, $actor, [$task, $task]);
    }

    /**
     * @param  list<array{operation: string, item_id: int, at: string, event_key: string, sellable: int, damaged: int, note: string}>  $tasks
     * @return list<Process>
     */
    private function runTasks(Order $order, User $actor, array $tasks): array
    {
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'return-inspection-'.Str::uuid();
        $worker = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$deadline = microtime(true) + 10;
while (! file_exists($argv[10]) && microtime(true) < $deadline) { usleep(10000); }
if (! file_exists($argv[10])) { fwrite(STDERR, 'barrier timeout'); exit(4); }
try {
    $actor = App\Models\User::query()->findOrFail((int) $argv[4]);
    if ($argv[1] === 'receive') {
        app(App\Actions\ReceiveReturnInspection::class)->handle($argv[2], (int) $argv[3], $actor, $argv[9], $argv[8]);
    } else {
        app(App\Actions\CompleteReturnInspection::class)->handle($argv[2], (int) $argv[3], $actor, $argv[9], (int) $argv[6], (int) $argv[7], $argv[8]);
    }
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
PHP;
        $processes = array_map(function (array $task) use ($worker, $order, $actor, $barrier): Process {
            $arguments = [
                $task['operation'],
                $order->order_code,
                (string) $task['item_id'],
                (string) $actor->id,
                $task['at'],
                (string) $task['sellable'],
                (string) $task['damaged'],
                $task['note'],
                $task['event_key'],
            ];
            $process = new Process([PHP_BINARY, '-r', $worker, ...$arguments, $barrier], base_path());
            $process->setTimeout(25);
            $process->start();

            return $process;
        }, $tasks);

        try {
            usleep(100000);
            file_put_contents($barrier, 'go');
            foreach ($processes as $process) {
                $process->wait();
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

    /** @param list<Process> $processes @return list<int> */
    private function exitCodes(array $processes): array
    {
        $codes = array_map(fn (Process $process): int => $process->getExitCode() ?? -1, $processes);
        sort($codes);

        return $codes;
    }

    /** @param list<Process> $processes */
    private function errors(array $processes): string
    {
        return implode(PHP_EOL, array_map(fn (Process $process): string => $process->getErrorOutput(), $processes));
    }

    private function assertQueryFails(callable $operation): void
    {
        try {
            $operation();
            $this->fail('MariaDB accepted an invalid Return Inspection mutation.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
