<?php

namespace Tests\Feature;

use App\Actions\InitiateVnPayPayment;
use App\Actions\ReleaseStockReservations;
use App\Enums\PaymentStatus;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\User;
use App\Services\VnPayGateway;
use App\ValueObjects\CheckoutRecipient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbVnPayCallbackConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || getenv('RUN_MARIADB_VNPAY_CALLBACK_QA') !== '1') {
            $this->markTestSkipped('Run with the isolated MariaDB VNPay callback QA runner.');
        }
        $this->assertStringContainsString('_vnpay_callback_qa_', DB::getDatabaseName());
        config()->set('services.vnpay', [
            'payment_url' => VnPayGateway::SANDBOX_PAYMENT_URL,
            'terminal_code' => 'ABCDEFGH', 'hash_secret' => 'qa-secret',
            'return_url' => 'https://qa.example.test/checkout/vnpay/return',
            'version' => '2.1.0', 'timezone' => 'Asia/Ho_Chi_Minh',
        ]);
    }

    public function test_mariadb_metadata_constraints_and_concurrent_callback_are_exactly_once(): void
    {
        $columns = collect(DB::select("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_attempts'"))->pluck('COLUMN_NAME');
        foreach (['gateway_transaction_status', 'gateway_paid_at', 'gateway_bank_code', 'callback_fingerprint'] as $column) {
            $this->assertContains($column, $columns);
        }
        $checks = collect(DB::select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'"))->pluck('CONSTRAINT_NAME');
        $this->assertContains('payment_attempts_callback_evidence_check', $checks);
        $this->assertContains('refunds_domain_check', $checks);

        [$attempt, $product] = $this->attempt();
        try {
            DB::table('payment_attempts')->where('id', $attempt->id)->update(['gateway_result_code' => '00']);
            $this->fail('MariaDB accepted partial callback evidence.');
        } catch (QueryException) {
            $this->assertNull($attempt->fresh()->gateway_result_code);
        }

        $beforeStock = $product->sellable_quantity;
        $processes = $this->runTwo($attempt);
        $this->assertSame([0, 0], $this->exitCodes($processes));
        $outputs = array_map(fn (Process $process): string => trim($process->getOutput()), $processes);
        sort($outputs);
        $this->assertSame(['duplicate', 'new'], $outputs);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertSame($beforeStock - 2, $product->fresh()->sellable_quantity);
        $this->assertSame(1, $attempt->stockReservations()->whereNotNull('consumed_at')->count());

        $refundAttempt = PaymentAttempt::factory()->create();
        $refundAttempt->finalizeCallback([
            'status' => PaymentStatus::Paid,
            'gateway_transaction_id' => (string) (900000000 + $refundAttempt->id),
            'gateway_result_code' => '00', 'gateway_transaction_status' => '00',
            'gateway_paid_at' => now(), 'gateway_bank_code' => 'NCB',
            'callback_fingerprint' => hash('sha256', 'refund-'.$refundAttempt->id),
            'verified_at' => now(), 'late_callback_exception' => false,
        ]);
        DB::table('refunds')->insert([
            'payment_attempt_id' => $refundAttempt->id, 'order_id' => null,
            'amount_vnd' => $refundAttempt->amount_vnd, 'reason' => 'stock_unavailable', 'status' => 'pending',
            'gateway_refund_reference' => null, 'note' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            DB::table('refunds')->where('payment_attempt_id', $refundAttempt->id)->update(['amount_vnd' => $refundAttempt->amount_vnd + 1]);
            $this->fail('MariaDB accepted mutation of immutable Refund evidence.');
        } catch (QueryException) {
            $this->assertDatabaseHas('refunds', ['payment_attempt_id' => $refundAttempt->id, 'amount_vnd' => $refundAttempt->amount_vnd]);
        }
        DB::table('refunds')->where('payment_attempt_id', $refundAttempt->id)->update(['status' => 'succeeded']);
        try {
            DB::table('refunds')->where('payment_attempt_id', $refundAttempt->id)->update(['status' => 'pending']);
            $this->fail('MariaDB allowed a terminal Refund to return to pending.');
        } catch (QueryException) {
            $this->assertDatabaseHas('refunds', ['payment_attempt_id' => $refundAttempt->id, 'status' => 'succeeded']);
        }

        foreach (['payment_attempts', 'refunds', 'orders', 'order_items', 'inventory_transactions'] as $table) {
            $status = DB::selectOne("CHECK TABLE `{$table}`");
            $this->assertSame('OK', strtoupper((string) $status->Msg_text));
        }
    }

    public function test_paid_and_failed_callbacks_compete_with_one_terminal_winner(): void
    {
        [$attempt, $product] = $this->attempt();
        $beforeStock = $product->sellable_quantity;

        $processes = $this->runTwo($attempt, ['success', 'failed']);
        $this->assertSame([0, 0], $this->exitCodes($processes));
        $outputs = array_map(fn (Process $process): string => trim($process->getOutput()), $processes);
        sort($outputs);
        $this->assertSame(['conflict', 'new'], $outputs);

        $attempt->refresh();
        if ($attempt->status === PaymentStatus::Paid) {
            $this->assertSame(1, $attempt->order()->count());
            $this->assertSame($beforeStock - 2, $product->fresh()->sellable_quantity);
            $this->assertNotNull($attempt->stockReservations()->sole()->consumed_at);
        } else {
            $this->assertSame(PaymentStatus::Failed, $attempt->status);
            $this->assertSame(0, $attempt->order()->count());
            $this->assertSame($beforeStock, $product->fresh()->sellable_quantity);
            $this->assertNotNull($attempt->stockReservations()->sole()->released_at);
        }
        $this->assertSame(0, $attempt->refund()->count());
    }

    public function test_callback_and_expiration_compete_without_lost_or_double_stock_effect(): void
    {
        [$attempt, $product] = $this->attempt();
        $beforeStock = $product->sellable_quantity;

        $processes = $this->runTwo($attempt, ['success', 'release']);
        $this->assertSame([0, 0], $this->exitCodes($processes));
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->status);
        $this->assertSame(1, $attempt->order()->count());
        $this->assertSame($beforeStock - 2, $product->fresh()->sellable_quantity);
        $reservation = $attempt->stockReservations()->sole();
        $this->assertNotSame($reservation->released_at === null, $reservation->consumed_at === null);
        $this->assertSame(1, DB::table('inventory_transactions')->whereIn('order_item_id', $attempt->order->items()->pluck('id'))->count());
    }

    public function test_concurrent_late_callbacks_create_one_pending_refund_and_no_order(): void
    {
        [$attempt, $product] = $this->attempt();
        app(ReleaseStockReservations::class)->handle($attempt, now()->addMinutes(16));
        $product->forceFill(['sellable_quantity' => 0])->save();

        $processes = $this->runTwo($attempt);
        $this->assertSame([0, 0], $this->exitCodes($processes));
        $outputs = array_map(fn (Process $process): string => trim($process->getOutput()), $processes);
        sort($outputs);
        $this->assertSame(['duplicate', 'new'], $outputs);
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->status);
        $this->assertSame(0, $attempt->order()->count());
        $this->assertSame(1, $attempt->refund()->count());
        $this->assertSame('pending', $attempt->refund->status->value);
        $this->assertSame(0, DB::table('inventory_transactions')->where('product_id', $product->id)->count());
    }

    public function test_callback_and_new_initiation_serialize_shared_stock_without_lost_capacity(): void
    {
        [$attempt, $product] = $this->attempt();
        $other = User::factory()->create();
        CartItem::factory()->for($other)->for($product)->create(['quantity' => 6]);
        $requestKey = (string) Str::uuid();

        $processes = $this->runTwo($attempt, ['success', 'initiate'], [
            'customer_id' => $other->id, 'request_key' => $requestKey, 'coupon_code' => null,
        ]);
        $this->assertSame([0, 0], $this->exitCodes($processes));
        $outputs = array_map(fn (Process $process): string => trim($process->getOutput()), $processes);
        sort($outputs);
        $this->assertSame(['initiate', 'new'], $outputs);

        $newAttempt = PaymentAttempt::query()->where('user_id', $other->id)->where('request_key', $requestKey)->sole();
        $this->assertSame(6, $newAttempt->stockReservations()->sole()->quantity);
        $this->assertSame(1, $attempt->fresh()->order()->count());
        $this->assertSame(6, $product->fresh()->sellable_quantity);
    }

    public function test_late_callback_and_new_initiation_have_one_winner_for_last_coupon_slot(): void
    {
        $coupon = Coupon::factory()->fixed(10_000)->create(['max_uses' => 1]);
        [$attempt] = $this->attempt($coupon);
        app(ReleaseStockReservations::class)->handle($attempt, now()->addMinutes(16));
        $other = User::factory()->create();
        $otherProduct = Product::factory()->inStock(3)->create(['price_vnd' => 150_000, 'sale_price_vnd' => null]);
        CartItem::factory()->for($other)->for($otherProduct)->create(['quantity' => 1]);
        $requestKey = (string) Str::uuid();

        $processes = $this->runTwo($attempt, ['success', 'initiate'], [
            'customer_id' => $other->id, 'request_key' => $requestKey, 'coupon_code' => $coupon->code,
        ]);
        $this->assertSame([0, 0], $this->exitCodes($processes));
        $outputs = array_map(fn (Process $process): string => trim($process->getOutput()), $processes);
        sort($outputs);
        $this->assertContains($outputs, [['initiate', 'new'], ['new', 'rejected']]);

        $attempt->refresh();
        $newAttemptExists = PaymentAttempt::query()->where('user_id', $other->id)->where('request_key', $requestKey)->exists();
        $this->assertNotSame($attempt->order()->exists(), $attempt->refund()->exists());
        $this->assertSame($attempt->refund()->exists(), $newAttemptExists);
        $this->assertSame(1, $coupon->usages()->get()->filter(fn ($usage): bool => $usage->isHoldingCapacityAt(now()) || $usage->status->value === 'consumed')->count());
    }

    private function attempt(?Coupon $coupon = null): array
    {
        $customer = User::factory()->create();
        $product = Product::factory()->inStock(8)->create(['price_vnd' => 150_000, 'sale_price_vnd' => null]);
        CartItem::factory()->for($customer)->for($product)->create(['quantity' => 2]);
        $attempt = app(InitiateVnPayPayment::class)->handle(
            $customer,
            new CheckoutRecipient('QA Receiver', 'qa@example.test', '0912345678', 'Ha Noi', 'Cau Giay', 'Dich Vong', '12 QA Street'),
            (string) Str::uuid(), $coupon?->code, '203.0.113.10',
        )->attempt;

        return [$attempt, $product];
    }

    private function runTwo(PaymentAttempt $attempt, array $modes = ['success', 'success'], array $extra = []): array
    {
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vnpay-callback-'.Str::uuid();
        $payload = base64_encode(json_encode(array_merge([
            'attempt_id' => $attempt->id,
            'reference' => $attempt->gateway_reference,
            'amount' => $attempt->amount_vnd,
            'transaction_id' => (string) (800000000 + $attempt->id),
        ], $extra), JSON_THROW_ON_ERROR));
        $worker = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$mode = $argv[2];
$deadline = microtime(true) + 10;
while (! file_exists($argv[3]) && microtime(true) < $deadline) { usleep(10000); }
if (! file_exists($argv[3])) { exit(4); }
try {
    if ($mode === 'release') {
        $attempt = App\Models\PaymentAttempt::query()->findOrFail($payload['attempt_id']);
        app(App\Actions\ReleaseStockReservations::class)->handle($attempt, now()->addMinutes(16));
        fwrite(STDOUT, 'release');
        exit(0);
    }
    if ($mode === 'initiate') {
        try {
            $customer = App\Models\User::query()->findOrFail($payload['customer_id']);
            $recipient = new App\ValueObjects\CheckoutRecipient(
                'QA Receiver', 'qa@example.test', '0912345678', 'Ha Noi', 'Cau Giay', 'Dich Vong', '12 QA Street'
            );
            app(App\Actions\CreatePaymentAttempt::class)->handle(
                $customer, $recipient, $payload['request_key'], $payload['coupon_code'], null, '203.0.113.11'
            );
            fwrite(STDOUT, 'initiate');
        } catch (Illuminate\Validation\ValidationException) {
            fwrite(STDOUT, 'rejected');
        }
        exit(0);
    }
    $successful = $mode === 'success';
    $callback = new App\ValueObjects\VnPayCallback(
        'ABCDEFGH', $payload['amount'], 'QA payment', $payload['reference'], $successful ? '00' : '24', $successful ? '00' : '02',
        $successful ? $payload['transaction_id'] : null, Carbon\CarbonImmutable::parse('2026-10-02 14:00:00', 'UTC'), 'NCB',
        ['vnp_TmnCode' => 'ABCDEFGH', 'vnp_Amount' => (string) ($payload['amount'] * 100),
         'vnp_OrderInfo' => 'QA payment', 'vnp_PayDate' => '20261002210000', 'vnp_ResponseCode' => $successful ? '00' : '24',
         'vnp_TxnRef' => $payload['reference'], 'vnp_TransactionNo' => $successful ? $payload['transaction_id'] : '0',
         'vnp_TransactionStatus' => $successful ? '00' : '02'],
    );
    $duplicate = app(App\Actions\FinalizeVnPayCallback::class)->handle($callback);
    fwrite(STDOUT, $duplicate ? 'duplicate' : 'new');
    exit(0);
} catch (App\Exceptions\VnPayCallbackConflict) {
    fwrite(STDOUT, 'conflict');
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
PHP;
        $processes = [];
        foreach ($modes as $mode) {
            $process = new Process([PHP_BINARY, '-r', $worker, $payload, $mode, $barrier], base_path());
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }
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

    private function exitCodes(array $processes): array
    {
        $codes = array_map(fn (Process $process): int => $process->getExitCode() ?? -1, $processes);
        sort($codes);

        return $codes;
    }
}
