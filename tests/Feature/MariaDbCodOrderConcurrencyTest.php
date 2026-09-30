<?php

namespace Tests\Feature;

use App\Actions\BuildCheckoutQuote;
use App\Actions\BuildCodOrderFingerprint;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\ValueObjects\CheckoutRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbCodOrderConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('RUN_MARIADB_COD_QA') !== '1') {
            $this->markTestSkipped('Run with the isolated MariaDB COD QA runner.');
        }
        if (DB::getDriverName() !== 'mysql'
            || ! str_contains((string) DB::getDatabaseName(), '_cod_order_qa_')) {
            $this->fail('COD concurrency QA requires the isolated marker database.');
        }
    }

    public function test_cod_locking_idempotency_capacity_and_rollback_on_mariadb(): void
    {
        $sameCustomer = $this->customerWithCart();
        $sameKey = (string) Str::uuid();
        $same = $this->workerPayload($sameCustomer, $sameKey);
        $results = $this->runTwo($same, $same);
        $this->assertSame([0, 0], $this->exitCodes($results));
        $sameOrder = Order::query()->where('user_id', $sameCustomer->id)->sole();
        $this->assertSame(1, $sameOrder->items()->count());
        $this->assertSame(1, $sameOrder->statusHistories()->count());
        $this->assertSame(1, InventoryTransaction::query()->whereIn('order_item_id', $sameOrder->items()->pluck('id'))->count());

        $conflictCustomer = $this->customerWithCart();
        $conflictKey = (string) Str::uuid();
        $first = $this->workerPayload($conflictCustomer, $conflictKey);
        $second = $this->workerPayload($conflictCustomer, $conflictKey, null, 'Người nhận khác');
        $this->assertSame([0, 2], $this->exitCodes($this->runTwo($first, $second)));
        $this->assertSame(1, Order::query()->where('user_id', $conflictCustomer->id)->count());

        $lastStock = Product::factory()->inStock(1)->create(['price_vnd' => 100_000]);
        $firstCustomer = $this->customerWithCart($lastStock);
        $secondCustomer = $this->customerWithCart($lastStock);
        $results = $this->runTwo(
            $this->workerPayload($firstCustomer, (string) Str::uuid()),
            $this->workerPayload($secondCustomer, (string) Str::uuid()),
        );
        $this->assertSame([0, 2], $this->exitCodes($results));
        $this->assertSame(0, $lastStock->refresh()->sellable_quantity);

        $coupon = Coupon::factory()->create(['max_uses' => 1]);
        $couponFirst = $this->customerWithCart();
        $couponSecond = $this->customerWithCart();
        $results = $this->runTwo(
            $this->workerPayload($couponFirst, (string) Str::uuid(), $coupon),
            $this->workerPayload($couponSecond, (string) Str::uuid(), $coupon),
        );
        $this->assertSame([0, 2], $this->exitCodes($results));
        $this->assertSame(1, $coupon->usages()->count());

        $perCustomerCoupon = Coupon::factory()->create(['max_uses' => 10, 'max_uses_per_user' => 1]);
        $perCustomer = $this->customerWithCart();
        $results = $this->runTwo(
            $this->workerPayload($perCustomer, (string) Str::uuid(), $perCustomerCoupon),
            $this->workerPayload($perCustomer, (string) Str::uuid(), $perCustomerCoupon),
        );
        $this->assertSame([0, 2], $this->exitCodes($results));
        $this->assertSame(1, $perCustomerCoupon->usages()->where('customer_id', $perCustomer->id)->count());

        $one = Product::factory()->inStock(5)->create(['price_vnd' => 80_000]);
        $two = Product::factory()->inStock(5)->create(['price_vnd' => 90_000]);
        $multiFirst = $this->customerWithCart($one, [$two]);
        $multiSecond = $this->customerWithCart($two, [$one]);
        $results = $this->runTwo(
            $this->workerPayload($multiFirst, (string) Str::uuid()),
            $this->workerPayload($multiSecond, (string) Str::uuid()),
        );
        $this->assertSame([0, 0], $this->exitCodes($results));

        $staleProduct = Product::factory()->inStock(2)->create(['price_vnd' => 100_000]);
        $staleCustomer = $this->customerWithCart($staleProduct);
        $stalePayload = $this->workerPayload($staleCustomer, (string) Str::uuid());
        $staleResult = $this->runWhileProductUpdateCommits($stalePayload, $staleProduct, 120_000);
        $this->assertSame(2, $staleResult->getExitCode(), $staleResult->getErrorOutput());
        $this->assertSame(0, Order::query()->where('user_id', $staleCustomer->id)->count());
        $this->assertDatabaseHas('cart_items', ['user_id' => $staleCustomer->id, 'product_id' => $staleProduct->id]);

        $replayCustomer = $this->customerWithCart();
        $replayKey = (string) Str::uuid();
        $payload = $this->workerPayload($replayCustomer, $replayKey);
        $this->assertSame([0], $this->exitCodes($this->runMany([$payload])));
        $newProduct = Product::factory()->inStock(3)->create();
        CartItem::factory()->for($replayCustomer)->for($newProduct)->create();
        $this->assertSame([0], $this->exitCodes($this->runMany([$payload])));
        $this->assertDatabaseHas('cart_items', ['user_id' => $replayCustomer->id, 'product_id' => $newProduct->id]);

        $rollbackCustomer = User::factory()->create();
        $rollbackFirst = Product::factory()->inStock(2)->create();
        $rollbackLast = Product::factory()->inStock(2)->create();
        CartItem::factory()->for($rollbackCustomer)->for($rollbackFirst)->create(['quantity' => 1]);
        CartItem::factory()->for($rollbackCustomer)->for($rollbackLast)->create(['quantity' => 1]);
        $rollbackPayload = $this->workerPayload($rollbackCustomer, (string) Str::uuid());
        DB::unprepared("CREATE TRIGGER cod_order_forced_failure BEFORE INSERT ON inventory_transactions
            FOR EACH ROW BEGIN IF NEW.product_id = {$rollbackLast->id} THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced final line failure';
            END IF; END");
        try {
            $this->assertSame([3], $this->exitCodes($this->runMany([$rollbackPayload])));
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS cod_order_forced_failure');
        }
        $this->assertSame(2, $rollbackFirst->refresh()->sellable_quantity);
        $this->assertSame(2, $rollbackLast->refresh()->sellable_quantity);
        $this->assertSame(0, Order::query()->where('user_id', $rollbackCustomer->id)->count());
        $this->assertSame(2, CartItem::query()->where('user_id', $rollbackCustomer->id)->count());
    }

    private function customerWithCart(?Product $first = null, array $others = []): User
    {
        $customer = User::factory()->create();
        $first ??= Product::factory()->inStock(5)->create(['price_vnd' => 100_000]);
        foreach ([$first, ...$others] as $product) {
            CartItem::factory()->for($customer)->for($product)->create(['quantity' => 1]);
        }

        return $customer;
    }

    private function workerPayload(User $customer, string $key, ?Coupon $coupon = null, string $name = 'QA Receiver'): array
    {
        $recipient = new CheckoutRecipient($name, 'qa@example.test', '0912345678', 'Ha Noi', 'Cau Giay', 'Dich Vong', '12 QA Street');
        $quote = app(BuildCheckoutQuote::class)->handle($customer, $recipient, $coupon?->code);
        $fingerprint = app(BuildCodOrderFingerprint::class)->handle($customer, $key, $quote, $coupon)['fingerprint'];

        return [
            'customer_id' => $customer->id,
            'request_key' => $key,
            'coupon_code' => $coupon?->code,
            'recipient_name' => $name,
            'fingerprint' => $fingerprint,
        ];
    }

    private function runWhileProductUpdateCommits(array $payload, Product $product, int $newPrice): Process
    {
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cod-order-'.Str::uuid();
        $process = null;
        DB::beginTransaction();

        try {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            DB::table('products')->where('id', $product->id)->update(['price_vnd' => $newPrice]);
            file_put_contents($barrier, 'go');
            $process = new Process([
                PHP_BINARY,
                '-r',
                $this->workerCode(),
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                $barrier,
            ], base_path());
            $process->setTimeout(25);
            $process->start();

            $deadline = microtime(true) + 8;
            $waiting = false;
            do {
                $blocked = DB::selectOne(<<<'SQL'
                    SELECT COUNT(*) AS aggregate
                    FROM information_schema.PROCESSLIST
                    WHERE DB = DATABASE()
                      AND ID <> CONNECTION_ID()
                      AND LOWER(COALESCE(INFO, '')) LIKE '%products%'
                      AND LOWER(COALESCE(INFO, '')) LIKE '%for update%'
                    SQL);
                $waiting = (int) $blocked->aggregate > 0;
                if (! $waiting) {
                    usleep(50_000);
                }
            } while (! $waiting && microtime(true) < $deadline);

            $this->assertTrue($waiting, 'COD worker never reached the Product row lock.');
            DB::commit();
            $process->wait();

            return $process;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop(1);
            }
            if (file_exists($barrier)) {
                unlink($barrier);
            }
        }
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
    $customer = App\Models\User::query()->findOrFail($payload['customer_id']);
    $recipient = new App\ValueObjects\CheckoutRecipient($payload['recipient_name'], 'qa@example.test', '0912345678', 'Ha Noi', 'Cau Giay', 'Dich Vong', '12 QA Street');
    app(App\Actions\CreateCodOrder::class)->handle($customer, $recipient, $payload['request_key'], $payload['coupon_code'], $payload['fingerprint']);
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

    /** @param list<array<string, mixed>> $payloads @return list<Process> */
    private function runMany(array $payloads): array
    {
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cod-order-'.Str::uuid();
        $worker = $this->workerCode();
        $processes = array_map(function (array $payload) use ($worker, $barrier): Process {
            $process = new Process([PHP_BINARY, '-r', $worker, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $barrier], base_path());
            $process->setTimeout(25);
            $process->start();

            return $process;
        }, $payloads);

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

    /** @return list<Process> */
    private function runTwo(array $first, array $second): array
    {
        return $this->runMany([$first, $second]);
    }

    /** @param list<Process> $processes @return list<int> */
    private function exitCodes(array $processes): array
    {
        $codes = array_map(fn (Process $process): int => $process->getExitCode() ?? -1, $processes);
        sort($codes);

        return $codes;
    }
}
