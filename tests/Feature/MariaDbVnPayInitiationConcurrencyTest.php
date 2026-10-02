<?php

namespace Tests\Feature;

use App\Actions\InitiateVnPayPayment;
use App\Exceptions\VnPayGatewayException;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\User;
use App\Services\VnPayGateway;
use App\ValueObjects\CheckoutRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbVnPayInitiationConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || getenv('RUN_MARIADB_VNPAY_INITIATION_QA') !== '1') {
            $this->markTestSkipped('Run with the isolated MariaDB VNPay initiation QA runner.');
        }
        $this->assertStringContainsString('_vnpay_initiation_qa_', DB::getDatabaseName());
    }

    private function customerWithCart(Product $product, array $extra = []): User
    {
        $customer = User::factory()->create();
        CartItem::factory()->for($customer)->for($product)->create(['quantity' => 1]);
        foreach ($extra as $item) {
            CartItem::factory()->for($customer)->for($item)->create(['quantity' => 1]);
        }

        return $customer;
    }

    public function test_initiation_is_serialized_for_idempotency_stock_coupon_and_multi_product_locks(): void
    {
        $column = DB::selectOne(<<<'SQL'
            SELECT IS_NULLABLE AS is_nullable, CHARACTER_MAXIMUM_LENGTH AS max_length
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_attempts'
              AND COLUMN_NAME = 'initiated_ip_address'
            SQL);
        $this->assertSame('YES', $column->is_nullable);
        $this->assertSame(45, (int) $column->max_length);

        $product = Product::factory()->inStock(5)->create(['price_vnd' => 100_000]);
        $customer = $this->customerWithCart($product);
        $key = (string) Str::uuid();
        $sameKey = $this->runTwo(
            $this->payload($customer, $key, ip: '203.0.113.10'),
            $this->payload($customer, $key, ip: '2001:db8::10'),
        );
        $this->assertSame([0, 0], $this->exitCodes($sameKey));
        $this->assertSame(trim($sameKey[0]->getOutput()), trim($sameKey[1]->getOutput()));
        $this->assertSame(1, $customer->paymentAttempts()->where('request_key', $key)->count());
        $this->assertSame(1, $product->stockReservations()->count());
        $this->assertContains(
            $customer->paymentAttempts()->where('request_key', $key)->value('initiated_ip_address'),
            ['203.0.113.10', '2001:db8::10'],
        );

        $lastStock = Product::factory()->inStock(1)->create(['price_vnd' => 100_000]);
        $stockRace = $this->runTwo(
            $this->payload($this->customerWithCart($lastStock)),
            $this->payload($this->customerWithCart($lastStock)),
        );
        $this->assertSame([0, 2], $this->exitCodes($stockRace));
        $this->assertSame(1, $lastStock->stockReservations()->count());

        $coupon = Coupon::factory()->create(['max_uses' => 1]);
        $couponRace = $this->runTwo(
            $this->payload($this->customerWithCart(Product::factory()->inStock(2)->create()), coupon: $coupon),
            $this->payload($this->customerWithCart(Product::factory()->inStock(2)->create()), coupon: $coupon),
        );
        $this->assertSame([0, 2], $this->exitCodes($couponRace));
        $this->assertSame(1, $coupon->usages()->count());

        $first = Product::factory()->inStock(4)->create(['price_vnd' => 100_000]);
        $second = Product::factory()->inStock(4)->create(['price_vnd' => 120_000]);
        $multi = $this->runTwo(
            $this->payload($this->customerWithCart($first, [$second])),
            $this->payload($this->customerWithCart($second, [$first])),
        );
        $this->assertSame([0, 0], $this->exitCodes($multi));

        $isolation = (string) DB::selectOne('SELECT @@tx_isolation AS level')->level;
        $rollbackProduct = Product::factory()->inStock(2)->create(['price_vnd' => 100_000]);
        $rollbackCustomer = $this->customerWithCart($rollbackProduct);
        $rollbackCoupon = Coupon::factory()->create();
        $before = [
            'attempts' => DB::table('payment_attempts')->count(),
            'reservations' => DB::table('stock_reservations')->count(),
            'usages' => DB::table('coupon_usages')->count(),
        ];
        $this->app->instance(VnPayGateway::class, new class extends VnPayGateway
        {
            public function buildPaymentUrl(PaymentAttempt $attempt): string
            {
                throw new VnPayGatewayException('Injected MariaDB build failure.');
            }
        });

        try {
            app(InitiateVnPayPayment::class)->handle(
                $rollbackCustomer,
                new CheckoutRecipient('QA Receiver', 'qa@example.test', '0912345678', 'Ha Noi', 'Cau Giay', 'Dich Vong', '12 QA Street'),
                (string) Str::uuid(),
                $rollbackCoupon->code,
                '203.0.113.10',
            );
            $this->fail('Injected gateway failure did not abort MariaDB initiation.');
        } catch (VnPayGatewayException) {
            $this->assertSame($before['attempts'], DB::table('payment_attempts')->count());
            $this->assertSame($before['reservations'], DB::table('stock_reservations')->count());
            $this->assertSame($before['usages'], DB::table('coupon_usages')->count());
            $this->assertSame($isolation, (string) DB::selectOne('SELECT @@tx_isolation AS level')->level);
        }
    }

    private function payload(User $customer, ?string $key = null, ?Coupon $coupon = null, string $ip = '203.0.113.10'): array
    {
        return [
            'customer_id' => $customer->id,
            'request_key' => $key ?? (string) Str::uuid(),
            'coupon_code' => $coupon?->code,
            'ip_address' => $ip,
        ];
    }

    /** @return list<int> */
    private function exitCodes(array $processes): array
    {
        $codes = array_map(fn (Process $process): int => $process->getExitCode() ?? -1, $processes);
        sort($codes);

        return $codes;
    }

    /** @return list<Process> */
    private function runTwo(array $firstPayload, array $secondPayload): array
    {
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vnpay-initiation-'.Str::uuid();
        $worker = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config()->set('services.vnpay', [
    'payment_url' => App\Services\VnPayGateway::SANDBOX_PAYMENT_URL,
    'terminal_code' => 'ABCDEFGH', 'hash_secret' => 'qa-secret',
    'return_url' => 'https://qa.example.test/checkout/vnpay/return',
    'version' => '2.1.0', 'timezone' => 'Asia/Ho_Chi_Minh',
]);
$payload = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$deadline = microtime(true) + 10;
while (! file_exists($argv[2]) && microtime(true) < $deadline) { usleep(10000); }
if (! file_exists($argv[2])) { exit(4); }
try {
    $customer = App\Models\User::query()->findOrFail($payload['customer_id']);
    $recipient = new App\ValueObjects\CheckoutRecipient('QA Receiver', 'qa@example.test', '0912345678', 'Ha Noi', 'Cau Giay', 'Dich Vong', '12 QA Street');
    $result = app(App\Actions\InitiateVnPayPayment::class)->handle($customer, $recipient, $payload['request_key'], $payload['coupon_code'], $payload['ip_address']);
    fwrite(STDOUT, hash('sha256', $result->paymentUrl));
    exit(0);
} catch (Illuminate\Validation\ValidationException $exception) {
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
PHP;

        $processes = array_map(function (array $payload) use ($worker, $barrier): Process {
            $process = new Process([PHP_BINARY, '-r', $worker, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $barrier], base_path());
            $process->setTimeout(25);
            $process->start();

            return $process;
        }, [$firstPayload, $secondPayload]);

        try {
            usleep(100000);
            file_put_contents($barrier, 'go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertNotSame(3, $process->getExitCode(), $process->getErrorOutput());
                $this->assertNotSame(4, $process->getExitCode(), 'Worker barrier timed out.');
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
}
