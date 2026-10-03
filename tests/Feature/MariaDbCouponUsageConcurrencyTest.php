<?php

namespace Tests\Feature;

use App\Actions\CreatePaymentAttempt;
use App\Actions\ReleaseCouponUsage;
use App\Enums\CouponUsageStatus;
use App\Enums\PaymentStatus;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\User;
use App\ValueObjects\CheckoutRecipient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbCouponUsageConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || getenv('RUN_MARIADB_COUPON_USAGE_QA') !== '1') {
            $this->markTestSkipped('Dedicated MariaDB concurrency QA only.');
        }
        $this->assertStringContainsString('_coupon_usage_qa_', DB::getDatabaseName());
    }

    private function recipient(): CheckoutRecipient
    {
        return new CheckoutRecipient('QA Receiver', 'qa@example.test', '0912345678', 'Ha Noi', 'Cau Giay', 'Dich Vong', '12 QA Street');
    }

    private function customerWithCart(?Product $product = null, array $extraProducts = []): User
    {
        $customer = User::factory()->create();
        $product ??= Product::factory()->inStock(20)->create(['price_vnd' => 100_000]);
        CartItem::factory()->for($customer)->for($product)->create(['quantity' => 1]);
        foreach ($extraProducts as $extra) {
            CartItem::factory()->for($customer)->for($extra)->create(['quantity' => 1]);
        }

        return $customer;
    }

    private function createPayload(User $customer, Coupon $coupon, ?string $key = null): array
    {
        return ['mode' => 'create', 'customer_id' => $customer->id, 'coupon_code' => $coupon->code, 'request_key' => $key ?? (string) Str::uuid()];
    }

    private function paidOrder($attempt): Order
    {
        $attempt->finalizeCallback([
            'status' => PaymentStatus::Paid,
            'gateway_transaction_id' => 'TX-'.str_replace('-', '', (string) Str::uuid()),
            'gateway_result_code' => '00',
            'gateway_transaction_status' => '00',
            'gateway_paid_at' => now(),
            'callback_fingerprint' => hash('sha256', (string) Str::uuid()),
            'verified_at' => now(),
        ]);

        return Order::factory()->forVerifiedAttempt($attempt)->create(['coupon_snapshot_json' => $attempt->pricing_snapshot_json['coupon']]);
    }

    public function test_coupon_usage_writers_are_serialized_across_real_processes(): void
    {
        $totalCoupon = Coupon::factory()->create(['max_uses' => 1]);
        $this->assertSame(0, $totalCoupon->usages()->count());
        $results = $this->runTwo(
            $this->createPayload($this->customerWithCart(), $totalCoupon),
            $this->createPayload($this->customerWithCart(), $totalCoupon),
        );
        $this->assertSame([0, 2], $this->exitCodes($results));
        $this->assertSame(1, $totalCoupon->usages()->count());

        $customerCoupon = Coupon::factory()->create(['max_uses_per_user' => 1]);
        $customer = $this->customerWithCart();
        $results = $this->runTwo($this->createPayload($customer, $customerCoupon), $this->createPayload($customer, $customerCoupon));
        $this->assertSame([0, 2], $this->exitCodes($results));
        $this->assertSame(1, $customerCoupon->usages()->count());

        $unlimited = Coupon::factory()->create();
        $results = $this->runTwo(
            $this->createPayload($this->customerWithCart(), $unlimited),
            $this->createPayload($this->customerWithCart(), $unlimited),
        );
        $this->assertSame([0, 0], $this->exitCodes($results));
        $this->assertSame(2, $unlimited->usages()->count());

        $sameKeyCoupon = Coupon::factory()->create();
        $sameKeyCustomer = $this->customerWithCart();
        $sameKey = (string) Str::uuid();
        $payload = $this->createPayload($sameKeyCustomer, $sameKeyCoupon, $sameKey);
        $results = $this->runTwo($payload, $payload);
        $this->assertSame([0, 0], $this->exitCodes($results));
        $this->assertSame(1, $sameKeyCustomer->paymentAttempts()->where('request_key', $sameKey)->count());
        $this->assertSame(1, $sameKeyCoupon->usages()->count());

        $sharedProduct = Product::factory()->inStock(1)->create(['price_vnd' => 100_000]);
        $stockCoupon = Coupon::factory()->create(['max_uses' => 1]);
        $results = $this->runTwo(
            $this->createPayload($this->customerWithCart($sharedProduct), $stockCoupon),
            $this->createPayload($this->customerWithCart($sharedProduct), $stockCoupon),
        );
        $this->assertSame([0, 2], $this->exitCodes($results));
        $this->assertSame(1, $stockCoupon->usages()->count());
        $this->assertSame(1, $sharedProduct->stockReservations()->count());

        $firstProduct = Product::factory()->inStock(5)->create(['price_vnd' => 100_000]);
        $secondProduct = Product::factory()->inStock(5)->create(['price_vnd' => 120_000]);
        $multiCoupon = Coupon::factory()->create();
        $results = $this->runTwo(
            $this->createPayload($this->customerWithCart($firstProduct, [$secondProduct]), $multiCoupon),
            $this->createPayload($this->customerWithCart($secondProduct, [$firstProduct]), $multiCoupon),
        );
        $this->assertSame([0, 0], $this->exitCodes($results));

        $releaseCoupon = Coupon::factory()->create();
        $releaseAttempt = app(CreatePaymentAttempt::class)->handle($this->customerWithCart(), $this->recipient(), (string) Str::uuid(), $releaseCoupon->code);
        $releasePayload = ['mode' => 'release', 'usage_id' => $releaseAttempt->couponUsage->id];
        $results = $this->runTwo($releasePayload, $releasePayload);
        $this->assertSame([0, 0], $this->exitCodes($results));
        $this->assertSame(CouponUsageStatus::Released, $releaseAttempt->couponUsage->fresh()->status);

        $consumeCoupon = Coupon::factory()->create();
        $consumeAttempt = app(CreatePaymentAttempt::class)->handle($this->customerWithCart(), $this->recipient(), (string) Str::uuid(), $consumeCoupon->code);
        $consumeOrder = $this->paidOrder($consumeAttempt);
        $consumePayload = ['mode' => 'consume', 'usage_id' => $consumeAttempt->couponUsage->id, 'order_id' => $consumeOrder->id];
        $results = $this->runTwo($consumePayload, $consumePayload);
        $this->assertSame([0, 0], $this->exitCodes($results));
        $this->assertSame(CouponUsageStatus::Consumed, $consumeAttempt->couponUsage->fresh()->status);

        $raceCoupon = Coupon::factory()->create();
        $raceAttempt = app(CreatePaymentAttempt::class)->handle($this->customerWithCart(), $this->recipient(), (string) Str::uuid(), $raceCoupon->code);
        $raceOrder = $this->paidOrder($raceAttempt);
        $results = $this->runTwo(
            ['mode' => 'release', 'usage_id' => $raceAttempt->couponUsage->id],
            ['mode' => 'consume', 'usage_id' => $raceAttempt->couponUsage->id, 'order_id' => $raceOrder->id],
        );
        $this->assertSame([0, 2], $this->exitCodes($results));
        $this->assertContains($raceAttempt->couponUsage->fresh()->status, [CouponUsageStatus::Released, CouponUsageStatus::Consumed]);

        $lateCoupon = Coupon::factory()->create(['max_uses' => 1]);
        $lateAttempt = app(CreatePaymentAttempt::class)->handle($this->customerWithCart(), $this->recipient(), (string) Str::uuid(), $lateCoupon->code);
        app(ReleaseCouponUsage::class)->handle($lateAttempt->couponUsage, 'expired');
        $lateOrder = $this->paidOrder($lateAttempt);
        $results = $this->runTwo(
            ['mode' => 'consume_late', 'usage_id' => $lateAttempt->couponUsage->id, 'order_id' => $lateOrder->id],
            $this->createPayload($this->customerWithCart(), $lateCoupon),
        );
        $this->assertSame([0, 2], $this->exitCodes($results));
        $this->assertSame(1, $lateCoupon->usages()->holdingCapacityAt(now())->count());

        $this->assertMariaDbSchemaEnforcesCouponUsageMetadataAndLifecycle();
    }

    private function assertMariaDbSchemaEnforcesCouponUsageMetadataAndLifecycle(): void
    {
        $table = DB::selectOne(<<<'SQL'
            SELECT ENGINE AS engine, TABLE_COLLATION AS table_collation
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupon_usages'
            SQL);
        $this->assertSame('InnoDB', $table->engine);
        $this->assertStringStartsWith('utf8mb4_', $table->table_collation);

        $precisions = collect(DB::select(<<<'SQL'
            SELECT COLUMN_NAME AS column_name, DATETIME_PRECISION AS datetime_precision
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupon_usages'
              AND COLUMN_NAME IN ('reserved_at','expires_at','consumed_at','released_at','created_at','updated_at')
            SQL))->pluck('datetime_precision', 'column_name');
        $this->assertCount(6, $precisions);
        $this->assertTrue($precisions->every(fn ($precision) => (int) $precision === 6));

        $lateColumn = DB::selectOne(<<<'SQL'
            SELECT IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, COLUMN_TYPE AS column_type
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupon_usages'
              AND COLUMN_NAME = 'late_callback_exception'
            SQL);
        $this->assertSame('NO', $lateColumn->is_nullable);
        $this->assertSame(0, (int) $lateColumn->column_default);
        $this->assertSame('tinyint(1)', strtolower($lateColumn->column_type));

        $indexes = collect(DB::select(<<<'SQL'
            SELECT DISTINCT INDEX_NAME AS index_name
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupon_usages'
            SQL))->pluck('index_name');
        foreach (['coupon_usages_attempt_unique', 'coupon_usages_order_unique', 'coupon_usages_capacity_index', 'coupon_usages_customer_capacity_index', 'coupon_usages_expiration_index'] as $index) {
            $this->assertContains($index, $indexes);
        }
        $this->assertNotContains('coupon_usages_attempt_status_index', $indexes);

        $foreignKeys = collect(DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME AS constraint_name, UPDATE_RULE AS update_rule, DELETE_RULE AS delete_rule
            FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'coupon_usages'
            SQL))->keyBy('constraint_name');
        foreach (['coupon_usages_coupon_id_foreign', 'coupon_usages_customer_id_foreign', 'coupon_usages_payment_attempt_id_foreign', 'coupon_usages_order_id_foreign'] as $foreignKey) {
            $this->assertTrue($foreignKeys->has($foreignKey));
            $this->assertSame('RESTRICT', $foreignKeys[$foreignKey]->update_rule);
            $this->assertSame('RESTRICT', $foreignKeys[$foreignKey]->delete_rule);
        }

        $constraints = collect(DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME AS constraint_name
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupon_usages'
            SQL))->pluck('constraint_name');
        foreach (['coupon_usages_status_check', 'coupon_usages_late_callback_check', 'coupon_usages_state_check', 'coupon_usages_time_check'] as $constraint) {
            $this->assertContains($constraint, $constraints);
        }
        $triggers = collect(DB::select(<<<'SQL'
            SELECT TRIGGER_NAME AS trigger_name
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'coupon_usages'
            SQL))->pluck('trigger_name');
        $this->assertEqualsCanonicalizing(['coupon_usages_lifecycle_update', 'coupon_usages_no_delete'], $triggers->all());

        $customer = User::factory()->create();
        $coupon = Coupon::factory()->create();
        $attempt = PaymentAttempt::factory()->create(['user_id' => $customer->id, 'coupon_id' => $coupon->id]);
        $usage = CouponUsage::factory()->create([
            'coupon_id' => $coupon->id,
            'customer_id' => $customer->id,
            'payment_attempt_id' => $attempt->id,
            'expires_at' => $attempt->expires_at,
        ]);
        $base = $usage->getAttributes();
        unset($base['id']);
        $otherAttempt = PaymentAttempt::factory()->create(['user_id' => $customer->id, 'coupon_id' => $coupon->id]);
        $base['payment_attempt_id'] = $otherAttempt->id;
        $order = Order::factory()->create();
        $terminalAt = $usage->reserved_at->addMinute()->format('Y-m-d H:i:s.u');

        foreach ([
            ['status' => 'RESERVED'],
            ['payment_attempt_id' => null],
            ['order_id' => $order->id],
            ['late_callback_exception' => 1],
            ['status' => 'released', 'payment_attempt_id' => null, 'released_at' => null],
            ['status' => 'released', 'payment_attempt_id' => null, 'released_at' => $terminalAt, 'late_callback_exception' => 1],
            ['status' => 'released', 'payment_attempt_id' => null, 'order_id' => $order->id, 'released_at' => $terminalAt],
            ['status' => 'released', 'payment_attempt_id' => null, 'consumed_at' => $terminalAt, 'released_at' => $terminalAt],
            ['status' => 'consumed', 'payment_attempt_id' => null, 'order_id' => null, 'consumed_at' => $terminalAt],
            ['status' => 'consumed', 'payment_attempt_id' => null, 'order_id' => $order->id, 'consumed_at' => null],
            ['status' => 'consumed', 'payment_attempt_id' => null, 'order_id' => $order->id, 'consumed_at' => $terminalAt, 'released_at' => $terminalAt],
            ['status' => 'consumed', 'order_id' => $order->id, 'consumed_at' => $terminalAt, 'late_callback_exception' => 1],
            ['status' => 'consumed', 'payment_attempt_id' => null, 'order_id' => $order->id, 'consumed_at' => $terminalAt, 'released_at' => $terminalAt, 'late_callback_exception' => 1],
            ['late_callback_exception' => 2],
            ['expires_at' => $usage->reserved_at->subSecond()->format('Y-m-d H:i:s.u')],
        ] as $invalid) {
            $this->assertMariaDbRejects(fn () => DB::table('coupon_usages')->insert(array_merge($base, $invalid)));
        }

        $this->assertMariaDbRejects(fn () => DB::table('coupon_usages')->where('id', $usage->id)->update(['status' => 'released']));
        $this->assertMariaDbRejects(fn () => DB::table('coupon_usages')->where('id', $usage->id)->delete());
        $this->assertDatabaseHas('coupon_usages', ['id' => $usage->id, 'status' => CouponUsageStatus::Reserved->value]);
    }

    private function assertMariaDbRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('MariaDB accepted an invalid Coupon Usage mutation.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    private function exitCodes(array $processes): array
    {
        $codes = array_map(fn (Process $process): int => $process->getExitCode() ?? -1, $processes);
        sort($codes);

        return $codes;
    }

    private function runTwo(array $firstPayload, array $secondPayload): array
    {
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'coupon-usage-'.Str::uuid();
        $worker = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$deadline = microtime(true) + 10;
while (! file_exists($argv[2]) && microtime(true) < $deadline) { usleep(10000); }
if (! file_exists($argv[2])) { fwrite(STDERR, 'barrier timeout'); exit(4); }
try {
    if ($payload['mode'] === 'create') {
        $customer = App\Models\User::query()->findOrFail($payload['customer_id']);
        $recipient = new App\ValueObjects\CheckoutRecipient('QA Receiver', 'qa@example.test', '0912345678', 'Ha Noi', 'Cau Giay', 'Dich Vong', '12 QA Street');
        app(App\Actions\CreatePaymentAttempt::class)->handle($customer, $recipient, $payload['request_key'], $payload['coupon_code']);
    } elseif ($payload['mode'] === 'release') {
        $usage = App\Models\CouponUsage::query()->findOrFail($payload['usage_id']);
        app(App\Actions\ReleaseCouponUsage::class)->handle($usage, 'concurrency QA');
    } elseif ($payload['mode'] === 'consume' || $payload['mode'] === 'consume_late') {
        $usage = App\Models\CouponUsage::query()->findOrFail($payload['usage_id']);
        $order = App\Models\Order::query()->findOrFail($payload['order_id']);
        app(App\Actions\ConsumeCouponUsage::class)->handle($usage, $order, $payload['mode'] === 'consume_late');
    } else {
        exit(5);
    }
    exit(0);
} catch (Illuminate\Validation\ValidationException $exception) {
    fwrite(STDERR, 'domain rejection');
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
PHP;

        $processes = array_map(function (array $payload) use ($worker, $barrier): Process {
            $process = new Process([PHP_BINARY, '-r', $worker, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $barrier], base_path());
            $process->setTimeout(20);
            $process->start();

            return $process;
        }, [$firstPayload, $secondPayload]);

        try {
            usleep(100000);
            file_put_contents($barrier, 'go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertNotSame(3, $process->getExitCode(), $process->getErrorOutput());
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
}
