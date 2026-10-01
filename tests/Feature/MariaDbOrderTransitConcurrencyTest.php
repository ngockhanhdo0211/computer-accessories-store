<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbOrderTransitConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('RUN_MARIADB_ORDER_TRANSIT_QA') !== '1') {
            $this->markTestSkipped('Run with the isolated MariaDB Order Transit QA runner.');
        }
        if (DB::getDriverName() !== 'mysql'
            || ! str_contains((string) DB::getDatabaseName(), '_order_transit_qa_')) {
            $this->fail('Order Transit concurrency QA requires the isolated marker database.');
        }
    }

    public function test_row_locking_makes_replays_idempotent_and_competing_events_single_winner(): void
    {
        $employee = User::factory()->employee()->create();

        $replayOrder = $this->placedOrder();
        $replayKey = (string) Str::uuid();
        $replayPayload = $this->payload($replayOrder, $employee, $replayKey);
        $this->assertSame([0, 0], $this->exitCodes($this->runTwo($replayPayload, $replayPayload)));
        $this->assertSame(OrderStatus::AwaitingHandoff, $replayOrder->refresh()->status);
        $this->assertSame(2, $replayOrder->statusHistories()->count());
        $this->assertSame(1, AuditLog::query()->where('request_id', $replayKey)->count());

        $conflictOrder = $this->placedOrder();
        $conflictKey = (string) Str::uuid();
        $this->assertSame([0, 2], $this->exitCodes($this->runTwo(
            $this->payload($conflictOrder, $employee, $conflictKey, 'Payload A'),
            $this->payload($conflictOrder, $employee, $conflictKey, 'Payload B'),
        )));
        $this->assertSame(OrderStatus::AwaitingHandoff, $conflictOrder->refresh()->status);
        $this->assertSame(2, $conflictOrder->statusHistories()->count());
        $this->assertSame(1, AuditLog::query()->where('request_id', $conflictKey)->count());

        $competingOrder = $this->placedOrder();
        $first = $this->payload($competingOrder, $employee, (string) Str::uuid());
        $second = $this->payload($competingOrder, $employee, (string) Str::uuid());
        $this->assertSame([0, 2], $this->exitCodes($this->runTwo($first, $second)));
        $this->assertSame(OrderStatus::AwaitingHandoff, $competingOrder->refresh()->status);
        $this->assertSame(2, $competingOrder->statusHistories()->count());
        $this->assertSame(1, AuditLog::query()
            ->where('subject_type', Order::class)
            ->where('subject_id', $competingOrder->id)
            ->where('action', 'order.status.transitioned')
            ->count());
    }

    private function placedOrder(): Order
    {
        $order = Order::factory()->for(User::factory(), 'customer')->create();
        OrderStatusHistory::factory()->for($order)->create();

        return $order;
    }

    /** @return array<string, int|string> */
    private function payload(Order $order, User $actor, string $eventKey, string $reason = 'MariaDB concurrency QA'): array
    {
        return [
            'order_id' => $order->id,
            'actor_id' => $actor->id,
            'target' => OrderStatus::AwaitingHandoff->value,
            'event_key' => $eventKey,
            'reason' => $reason,
        ];
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
    $order = App\Models\Order::query()->findOrFail($payload['order_id']);
    $actor = App\Models\User::query()->findOrFail($payload['actor_id']);
    app(App\Actions\TransitionOrderStatus::class)->handle(
        $order->order_code,
        $actor,
        App\Enums\OrderStatus::from($payload['target']),
        $payload['event_key'],
        $payload['reason'],
    );
    exit(0);
} catch (Illuminate\Validation\ValidationException $exception) {
    fwrite(STDERR, 'domain rejection');
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
PHP;
    }

    /** @param array<string, int|string> $first @param array<string, int|string> $second @return list<Process> */
    private function runTwo(array $first, array $second): array
    {
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'order-transit-'.Str::uuid();
        $worker = $this->workerCode();
        $processes = array_map(function (array $payload) use ($worker, $barrier): Process {
            $process = new Process([
                PHP_BINARY,
                '-r',
                $worker,
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                $barrier,
            ], base_path());
            $process->setTimeout(25);
            $process->start();

            return $process;
        }, [$first, $second]);

        try {
            usleep(100000);
            file_put_contents($barrier, 'go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertNotSame(4, $process->getExitCode(), $process->getErrorOutput());
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
}
