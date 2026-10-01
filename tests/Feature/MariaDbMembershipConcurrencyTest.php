<?php

namespace Tests\Feature;

use App\Actions\ApplyDeliveredOrderMembershipSpending;
use App\Enums\MembershipLevel;
use App\Enums\OrderStatus;
use App\Models\MembershipHistory;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbMembershipConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('RUN_MARIADB_MEMBERSHIP_QA') !== '1') {
            $this->markTestSkipped('Run with the isolated MariaDB Membership QA runner.');
        }
        if (DB::getDriverName() !== 'mysql'
            || ! str_contains((string) DB::getDatabaseName(), '_membership_qa_')) {
            $this->fail('Membership concurrency QA requires the isolated marker database.');
        }
    }

    public function test_concurrent_delivered_orders_produce_one_membership_projection(): void
    {
        $customer = User::factory()->create();
        $orders = [
            $this->deliveredOrder($customer, 6_000_000, 1_000_000),
            $this->deliveredOrder($customer, 5_500_000, 500_000),
        ];

        $processes = $this->runTwo($orders[0], $orders[1]);
        $this->assertSame([0, 0], $this->exitCodes($processes), $this->errors($processes));

        $customer->refresh();
        $this->assertSame(10_000_000, $customer->membership_spending);
        $this->assertSame(MembershipLevel::Bac, $customer->current_tier);
        $this->assertSame(1, MembershipHistory::query()->where('user_id', $customer->id)->count());
    }

    public function test_mariadb_fk_and_triggers_preserve_append_only_history(): void
    {
        $customer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $history = new MembershipHistory;
        $history->forceFill([
            'user_id' => $customer->id,
            'old_tier' => MembershipLevel::Dong,
            'new_tier' => MembershipLevel::Bac,
            'spending_vnd' => 5_000_000,
            'reason' => 'recalculation',
            'requested_by' => $admin->id,
            'created_at' => now(),
        ])->save();

        $admin->delete();
        $this->assertNull($history->refresh()->requested_by);

        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('membership_histories')->where('id', $history->id);
                $operation === 'update' ? $query->update(['reason' => 'changed']) : $query->delete();
                $this->fail('MariaDB must reject Membership History '.$operation.'.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseHas('membership_histories', [
            'id' => $history->id,
            'reason' => 'recalculation',
            'requested_by' => null,
        ]);
    }

    public function test_mariadb_unsigned_bigint_outside_php_range_is_rejected_without_clamping(): void
    {
        $customer = User::factory()->create();
        $order = $this->deliveredOrder($customer, 1, 0);
        $outsidePhpRange = '9223372036854775808';
        DB::table('orders')->where('id', $order->id)->update([
            'items_subtotal_vnd' => $outsidePhpRange,
            'item_discount_vnd' => 0,
            'shipping_fee_vnd' => 0,
            'shipping_discount_vnd' => 0,
            'total_vnd' => $outsidePhpRange,
        ]);

        try {
            app(ApplyDeliveredOrderMembershipSpending::class)->handle($order);
            $this->fail('Money outside the PHP integer range must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('supported integer range', $exception->getMessage());
        }

        $this->assertSame(0, $customer->fresh()->membership_spending);
        $this->assertSame(MembershipLevel::Dong, $customer->fresh()->current_tier);
        $this->assertSame(0, MembershipHistory::query()->where('user_id', $customer->id)->count());
    }

    private function deliveredOrder(User $customer, int $subtotal, int $discount): Order
    {
        return Order::factory()->for($customer, 'customer')->create([
            'status' => OrderStatus::Delivered,
            'items_subtotal_vnd' => $subtotal,
            'item_discount_vnd' => $discount,
            'shipping_fee_vnd' => 900_000,
            'shipping_discount_vnd' => 900_000,
            'total_vnd' => $subtotal - $discount,
            'delivered_at' => now(),
        ]);
    }

    /** @return list<Process> */
    private function runTwo(Order $first, Order $second): array
    {
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'membership-'.Str::uuid();
        $worker = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$deadline = microtime(true) + 10;
while (! file_exists($argv[2]) && microtime(true) < $deadline) { usleep(10000); }
if (! file_exists($argv[2])) { fwrite(STDERR, 'barrier timeout'); exit(4); }
try {
    $order = App\Models\Order::query()->findOrFail((int) $argv[1]);
    app(App\Actions\ApplyDeliveredOrderMembershipSpending::class)->handle($order);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
PHP;
        $processes = array_map(function (Order $order) use ($worker, $barrier): Process {
            $process = new Process([PHP_BINARY, '-r', $worker, (string) $order->id, $barrier], base_path());
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

    /** @param list<Process> $processes */
    private function errors(array $processes): string
    {
        return implode(PHP_EOL, array_map(fn (Process $process): string => $process->getErrorOutput(), $processes));
    }
}
