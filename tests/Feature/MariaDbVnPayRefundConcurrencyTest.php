<?php

namespace Tests\Feature;

use App\Enums\CouponUsageStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundGatewayAttemptStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\RefundGatewayAttempt;
use App\Models\User;
use App\Services\VnPayRefundGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbVnPayRefundConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || getenv('RUN_MARIADB_VNPAY_REFUND_QA') !== '1') {
            $this->markTestSkipped('Run with the isolated MariaDB VNPay Refund QA runner.');
        }
        $this->assertStringContainsString('_vnpay_refund_qa_', DB::getDatabaseName());
        config()->set('services.vnpay', [
            'refund_url' => VnPayRefundGateway::SANDBOX_REFUND_URL,
            'terminal_code' => 'ABCDEFGH', 'hash_secret' => 'qa-refund-secret',
            'version' => '2.1.0', 'timezone' => 'Asia/Ho_Chi_Minh',
            'refund_create_by' => 'refund-qa', 'refund_ip_address' => '203.0.113.10',
            'refund_connect_timeout' => 5, 'refund_timeout' => 15,
            'refund_submission_stale_seconds' => 60,
        ]);
    }

    public function test_metadata_direct_sql_and_concurrent_submit_and_reconciliation_are_at_most_once(): void
    {
        $checks = collect(DB::select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_gateway_attempts'"))->pluck('CONSTRAINT_NAME');
        $this->assertContains('refund_gateway_attempts_shape_check', $checks);
        $indexes = collect(DB::select("SELECT INDEX_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_gateway_attempts'"))->pluck('INDEX_NAME');
        $this->assertContains('refund_gateway_attempts_refund_unique', $indexes);
        $this->assertContains('refund_gateway_attempts_request_unique', $indexes);

        [$refund, $admin, $usage] = $this->pendingRefundWithCoupon();
        $callLog = tempnam(sys_get_temp_dir(), 'refund-http-');
        $barrier = tempnam(sys_get_temp_dir(), 'refund-barrier-');
        if ($callLog === false || $barrier === false) {
            $this->fail('Could not create concurrency fixture files.');
        }
        @unlink($barrier);
        try {
            $processes = $this->runTwo('submit', $refund, $admin, $barrier, $callLog);
            touch($barrier);
            $this->waitFor($processes);
            $this->assertSame([0, 0], $this->exitCodes($processes));
            $this->assertSame(1, count(file($callLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []));
            $this->assertDatabaseCount('refund_gateway_attempts', 1);
            $this->assertDatabaseHas('refund_gateway_attempts', ['refund_id' => $refund->id, 'status' => 'ambiguous']);

            @unlink($barrier);
            $processes = $this->runTwo('reconcile', $refund, $admin, $barrier, $callLog);
            touch($barrier);
            $this->waitFor($processes);
            $this->assertSame([0, 0], $this->exitCodes($processes));
            $outputs = array_map(fn (Process $process): string => trim($process->getOutput()), $processes);
            sort($outputs);
            $this->assertSame(['rejected', 'succeeded'], $outputs);
            $this->assertSame(RefundStatus::Succeeded, $refund->fresh()->status);
            $this->assertSame(PaymentStatus::Refunded, $refund->paymentAttempt->fresh()->status);
            $this->assertSame(CouponUsageStatus::Released, $usage->fresh()->status);
            $this->assertSame(1, DB::table('coupon_usages')->where('id', $usage->id)->where('release_reason', 'vnpay_refund_succeeded')->count());

            [$lateRefund, $lateAdmin] = $this->pendingRefundWithCoupon();
            $this->createSubmittedAttempt($lateRefund, $lateAdmin);
            @unlink($barrier);
            $processes = $this->runModes(['mark', 'finalize_success'], $lateRefund, $lateAdmin, $barrier, $callLog);
            touch($barrier);
            $this->waitFor($processes);
            $this->assertSame([0, 0], $this->exitCodes($processes));
            $this->assertSame(RefundStatus::Succeeded, $lateRefund->fresh()->status);
            $this->assertSame(PaymentStatus::Refunded, $lateRefund->paymentAttempt->fresh()->status);
            $this->assertSame(RefundGatewayAttemptStatus::Succeeded, $lateRefund->gatewayAttempt->fresh()->status);

            [$competingRefund, $competingAdmin] = $this->pendingRefundWithCoupon();
            $this->createSubmittedAttempt($competingRefund, $competingAdmin);
            @unlink($barrier);
            $processes = $this->runModes(['finalize_success', 'finalize_failed'], $competingRefund, $competingAdmin, $barrier, $callLog);
            touch($barrier);
            $this->waitFor($processes);
            $this->assertSame([0, 0], $this->exitCodes($processes));
            $terminalGateway = $competingRefund->gatewayAttempt->fresh();
            $this->assertContains($terminalGateway->status, [RefundGatewayAttemptStatus::Succeeded, RefundGatewayAttemptStatus::Failed]);
            $this->assertSame($terminalGateway->status->value, $competingRefund->fresh()->status->value);
            $this->assertSame(
                $terminalGateway->status === RefundGatewayAttemptStatus::Succeeded ? PaymentStatus::Refunded : PaymentStatus::Paid,
                $competingRefund->paymentAttempt->fresh()->status,
            );
            $this->assertSame(1, DB::table('audit_logs')->where('subject_type', Refund::class)
                ->where('subject_id', $competingRefund->id)->where('action', 'like', 'refund.vnpay.%')->count());

            try {
                DB::table('refund_gateway_attempts')->where('refund_id', $refund->id)->delete();
                $this->fail('MariaDB deleted gateway evidence.');
            } catch (QueryException) {
                $this->assertDatabaseHas('refund_gateway_attempts', ['refund_id' => $refund->id]);
            }
            foreach (['refund_gateway_attempts', 'refunds', 'payment_attempts', 'coupon_usages'] as $table) {
                $status = DB::selectOne("CHECK TABLE `{$table}`");
                $this->assertSame('OK', strtoupper((string) $status->Msg_text));
            }
        } finally {
            @unlink($barrier);
            @unlink($callLog);
        }
    }

    private function pendingRefundWithCoupon(): array
    {
        $coupon = Coupon::factory()->create();
        $attempt = PaymentAttempt::factory()->create(['coupon_id' => $coupon->id]);
        $attempt->finalizeCallback([
            'status' => PaymentStatus::Paid,
            'gateway_transaction_id' => (string) (700000000 + $attempt->id),
            'gateway_result_code' => '00', 'gateway_transaction_status' => '00',
            'gateway_paid_at' => now(), 'callback_fingerprint' => hash('sha256', 'qa-'.$attempt->id),
            'verified_at' => now(),
        ]);
        $snapshot = [
            'coupon_id' => $coupon->id, 'code' => $coupon->code, 'type' => $coupon->type->value,
            'scope' => $coupon->scope->value, 'value' => $coupon->value,
            'eligible_subtotal_vnd' => $attempt->pricing_snapshot_json['cart_subtotal_vnd'],
        ];
        $order = Order::factory()->forVerifiedAttempt($attempt)->create(['coupon_snapshot_json' => $snapshot]);
        $usage = (new CouponUsage)->forceFill([
            'coupon_id' => $coupon->id, 'customer_id' => $attempt->user_id,
            'payment_attempt_id' => $attempt->id, 'order_id' => $order->id,
            'status' => CouponUsageStatus::Consumed, 'reserved_at' => now()->subMinutes(2),
            'expires_at' => now()->addMinutes(13), 'consumed_at' => now()->subMinute(),
            'released_at' => null, 'release_reason' => null, 'late_callback_exception' => false,
            'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinute(),
        ]);
        $usage->save();
        $refund = (new Refund)->forceFill([
            'payment_attempt_id' => $attempt->id, 'order_id' => $order->id,
            'amount_vnd' => $attempt->amount_vnd, 'reason' => RefundReason::StockUnavailable,
            'status' => RefundStatus::Pending, 'gateway_refund_reference' => null, 'note' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $refund->save();

        return [$refund, User::factory()->admin()->create(), $usage];
    }

    private function runTwo(string $mode, Refund $refund, User $admin, string $barrier, string $callLog): array
    {
        return $this->runModes([$mode, $mode], $refund, $admin, $barrier, $callLog);
    }

    private function runModes(array $modes, Refund $refund, User $admin, string $barrier, string $callLog): array
    {
        $worker = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config()->set('services.vnpay', [
    'refund_url' => App\Services\VnPayRefundGateway::SANDBOX_REFUND_URL,
    'terminal_code' => 'ABCDEFGH', 'hash_secret' => 'qa-refund-secret',
    'version' => '2.1.0', 'timezone' => 'Asia/Ho_Chi_Minh',
    'refund_create_by' => 'refund-qa', 'refund_ip_address' => '203.0.113.10',
    'refund_connect_timeout' => 5, 'refund_timeout' => 15,
    'refund_submission_stale_seconds' => 60,
]);
$deadline = microtime(true) + 10;
while (! file_exists($argv[4]) && microtime(true) < $deadline) { usleep(10000); }
if (! file_exists($argv[4])) { exit(4); }
$refund = App\Models\Refund::query()->findOrFail((int) $argv[2]);
$admin = App\Models\User::query()->findOrFail((int) $argv[3]);
try {
    if ($argv[1] === 'submit') {
        $transport = new class($argv[5]) implements App\Contracts\VnPayRefundTransport {
            public function __construct(private string $callLog) {}
            public function send(App\ValueObjects\VnPayRefundRequest $request): App\ValueObjects\VnPayRefundResult {
                file_put_contents($this->callLog, $request->requestId().PHP_EOL, FILE_APPEND | LOCK_EX);
                usleep(150000);
                return new App\ValueObjects\VnPayRefundResult(
                    App\Enums\RefundGatewayAttemptStatus::Ambiguous, '94', '05', null,
                    hash('sha256', 'ambiguous'), Carbon\CarbonImmutable::now('UTC')
                );
            }
        };
        app()->instance(App\Contracts\VnPayRefundTransport::class, $transport);
        app(App\Actions\SubmitVnPayRefund::class)->handle($refund, $admin, (string) Illuminate\Support\Str::uuid());
        fwrite(STDOUT, 'submitted');
    } elseif ($argv[1] === 'mark') {
        app(App\Actions\MarkSubmittedRefundAmbiguous::class)->handle(
            $refund, $admin, (string) Illuminate\Support\Str::uuid(), 'QA stale submission'
        );
        fwrite(STDOUT, 'marked');
    } elseif (str_starts_with($argv[1], 'finalize_')) {
        $gateway = $refund->gatewayAttempt()->firstOrFail();
        $request = app(App\Services\VnPayRefundGateway::class)->buildRequest(
            $refund, $refund->paymentAttempt, $gateway->request_id, $gateway->submitted_at
        );
        $succeeded = $argv[1] === 'finalize_success';
        $result = new App\ValueObjects\VnPayRefundResult(
            $succeeded ? App\Enums\RefundGatewayAttemptStatus::Succeeded : App\Enums\RefundGatewayAttemptStatus::Failed,
            $succeeded ? '00' : '95', $succeeded ? '00' : '09', '8'.str_pad((string) $gateway->id, 8, '0', STR_PAD_LEFT),
            hash('sha256', $argv[1].$gateway->id), Carbon\CarbonImmutable::now('UTC')
        );
        app(App\Actions\FinalizeVnPayRefund::class)->handle($gateway->id, $request, $result);
        fwrite(STDOUT, $succeeded ? 'succeeded' : 'failed');
    } else {
        app(App\Actions\ReconcileVnPayRefund::class)->handle(
            $refund, $admin, (string) Illuminate\Support\Str::uuid(), 'succeeded', 'QA Merchant Portal confirmed'
        );
        fwrite(STDOUT, 'succeeded');
    }
} catch (Illuminate\Validation\ValidationException) {
    fwrite(STDOUT, 'rejected');
}
PHP;
        $processes = [];
        foreach ($modes as $mode) {
            $process = new Process([PHP_BINARY, '-r', $worker, $mode, (string) $refund->id, (string) $admin->id, $barrier, $callLog], base_path(), null, null, 20);
            $process->start();
            $processes[] = $process;
        }

        return $processes;
    }

    private function createSubmittedAttempt(Refund $refund, User $admin): RefundGatewayAttempt
    {
        $submittedAt = CarbonImmutable::now('UTC')->subSeconds(61);
        $requestId = 'RF'.strtoupper(bin2hex(random_bytes(15)));
        $request = app(VnPayRefundGateway::class)->buildRequest(
            $refund,
            $refund->paymentAttempt,
            $requestId,
            $submittedAt,
        );

        return RefundGatewayAttempt::factory()->create([
            'refund_id' => $refund->id,
            'submitted_by' => $admin->id,
            'submission_event_key' => (string) Str::uuid(),
            'request_id' => $requestId,
            'request_fingerprint' => $request->requestFingerprint,
            'amount_vnd' => $refund->amount_vnd,
            'submitted_at' => $submittedAt,
        ]);
    }

    private function waitFor(array $processes): void
    {
        foreach ($processes as $process) {
            $process->wait();
        }
    }

    private function exitCodes(array $processes): array
    {
        return array_map(fn (Process $process): int => $process->getExitCode(), $processes);
    }
}
