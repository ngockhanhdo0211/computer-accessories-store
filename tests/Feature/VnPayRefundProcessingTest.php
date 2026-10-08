<?php

namespace Tests\Feature;

use App\Actions\MarkSubmittedRefundAmbiguous;
use App\Actions\ReconcileVnPayRefund;
use App\Actions\SubmitVnPayRefund;
use App\Contracts\VnPayRefundTransport;
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
use App\ValueObjects\VnPayRefundRequest;
use App\ValueObjects\VnPayRefundResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class VnPayRefundProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.vnpay', [
            'payment_url' => 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html',
            'refund_url' => VnPayRefundGateway::SANDBOX_REFUND_URL,
            'terminal_code' => 'ABCDEFGH',
            'hash_secret' => 'refund-feature-secret',
            'return_url' => 'https://shop.example.test/checkout/vnpay/return',
            'version' => '2.1.0',
            'timezone' => 'Asia/Ho_Chi_Minh',
            'refund_create_by' => 'refund-system',
            'refund_ip_address' => '203.0.113.10',
            'refund_connect_timeout' => 5,
            'refund_timeout' => 15,
            'refund_submission_stale_seconds' => 60,
        ]);
    }

    public function test_schema_model_relationship_and_database_guards_protect_gateway_evidence(): void
    {
        $gateway = RefundGatewayAttempt::factory()->create();
        $this->assertTrue(Schema::hasColumns('refund_gateway_attempts', [
            'refund_id', 'submitted_by', 'submission_event_key', 'request_id', 'request_fingerprint',
            'amount_vnd', 'submitted_at', 'response_code', 'transaction_status', 'gateway_reference',
            'response_fingerprint', 'completed_at', 'status', 'reconciled_by',
            'reconciliation_event_key', 'reconciliation_fingerprint', 'reconciliation_note', 'reconciled_at',
        ]));
        $this->assertSame(RefundGatewayAttemptStatus::Submitted, $gateway->status);
        $this->assertTrue($gateway->refund->gatewayAttempt->is($gateway));
        $this->assertTrue($gateway->submitter->submittedRefundGatewayAttempts->contains($gateway));

        foreach ([
            fn () => $gateway->forceFill(['request_id' => 'RF'.str_repeat('A', 30)])->save(),
            fn () => $gateway->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Gateway evidence mutation should be rejected.');
            } catch (LogicException) {
                $this->assertDatabaseHas('refund_gateway_attempts', ['id' => $gateway->id]);
            }
        }

        try {
            DB::table('refund_gateway_attempts')->where('id', $gateway->id)->update(['amount_vnd' => $gateway->amount_vnd + 1]);
            $this->fail('Database changed immutable gateway evidence.');
        } catch (QueryException) {
            $this->assertDatabaseHas('refund_gateway_attempts', ['id' => $gateway->id, 'amount_vnd' => $gateway->amount_vnd]);
        }
        foreach ([
            fn () => RefundGatewayAttempt::factory()->create(['refund_id' => $gateway->refund_id]),
            fn () => DB::table('refund_gateway_attempts')->where('id', $gateway->id)->delete(),
            fn () => DB::table('refund_gateway_attempts')->where('id', $gateway->id)->update(['status' => 'succeeded', 'completed_at' => now()]),
        ] as $invalidMutation) {
            try {
                $invalidMutation();
                $this->fail('Database accepted duplicate, delete or invalid terminal evidence.');
            } catch (QueryException) {
                $this->assertDatabaseHas('refund_gateway_attempts', ['id' => $gateway->id, 'status' => 'submitted']);
            }
        }
    }

    public function test_success_is_at_most_once_and_replay_never_sends_a_second_http_request(): void
    {
        [$refund] = $this->pendingRefund();
        $admin = User::factory()->admin()->create();
        $transport = new FakeRefundTransport($this->refundResult(RefundGatewayAttemptStatus::Succeeded, '00', '00'));
        $this->app->instance(VnPayRefundTransport::class, $transport);

        $gateway = app(SubmitVnPayRefund::class)->handle($refund, $admin, (string) Str::uuid());
        $replay = app(SubmitVnPayRefund::class)->handle($refund->fresh(), $admin, (string) Str::uuid());

        $this->assertTrue($gateway->is($replay));
        $this->assertSame(1, $transport->calls);
        $this->assertMatchesRegularExpression('/^RF[0-9A-F]{30}$/', $gateway->request_id);
        $this->assertSame(RefundGatewayAttemptStatus::Succeeded, $gateway->status);
        $this->assertSame(RefundStatus::Succeeded, $refund->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $refund->paymentAttempt->fresh()->status);
        $this->assertDatabaseCount('refund_gateway_attempts', 1);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_definitive_failure_keeps_payment_paid_and_does_not_release_coupon(): void
    {
        [$refund, $usage] = $this->pendingRefund(true);
        $admin = User::factory()->admin()->create();
        $transport = new FakeRefundTransport($this->refundResult(RefundGatewayAttemptStatus::Failed, '95', '09'));
        $this->app->instance(VnPayRefundTransport::class, $transport);

        app(SubmitVnPayRefund::class)->handle($refund, $admin, (string) Str::uuid());

        $this->assertSame(RefundStatus::Failed, $refund->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $refund->paymentAttempt->fresh()->status);
        $this->assertSame(CouponUsageStatus::Consumed, $usage->fresh()->status);
    }

    public function test_ambiguous_result_requires_manual_reconciliation_and_success_releases_coupon_once(): void
    {
        [$refund, $usage] = $this->pendingRefund(true);
        $admin = User::factory()->admin()->create();
        $transport = new FakeRefundTransport($this->refundResult(RefundGatewayAttemptStatus::Ambiguous, '94', '05'));
        $this->app->instance(VnPayRefundTransport::class, $transport);
        $gateway = app(SubmitVnPayRefund::class)->handle($refund, $admin, (string) Str::uuid());

        $this->assertSame(RefundGatewayAttemptStatus::Ambiguous, $gateway->status);
        $this->assertSame(RefundStatus::Pending, $refund->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $refund->paymentAttempt->fresh()->status);
        $this->assertSame(CouponUsageStatus::Consumed, $usage->fresh()->status);

        $eventKey = (string) Str::uuid();
        $first = app(ReconcileVnPayRefund::class)->handle($refund, $admin, $eventKey, 'succeeded', 'Đã đối chiếu Merchant Portal', 'MANUAL-123');
        $replay = app(ReconcileVnPayRefund::class)->handle($refund->fresh(), $admin, $eventKey, 'succeeded', 'Đã đối chiếu Merchant Portal', 'MANUAL-123');

        $this->assertTrue($first->is($replay));
        $this->assertSame(RefundStatus::Succeeded, $refund->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $refund->paymentAttempt->fresh()->status);
        $this->assertSame(CouponUsageStatus::Released, $usage->fresh()->status);
        $this->assertSame('vnpay_refund_succeeded', $usage->fresh()->release_reason);
        $this->assertDatabaseCount('audit_logs', 3);

        $this->expectException(ValidationException::class);
        app(ReconcileVnPayRefund::class)->handle($refund->fresh(), $admin, (string) Str::uuid(), 'failed', 'Kết quả xung đột');
    }

    public function test_ambiguous_transport_result_is_not_retried(): void
    {
        [$refund] = $this->pendingRefund();
        $admin = User::factory()->admin()->create();
        $transport = new FakeRefundTransport($this->refundResult(RefundGatewayAttemptStatus::Ambiguous, null, null));
        $this->app->instance(VnPayRefundTransport::class, $transport);

        $gateway = app(SubmitVnPayRefund::class)->handle($refund, $admin, (string) Str::uuid());
        app(SubmitVnPayRefund::class)->handle($refund->fresh(), $admin, (string) Str::uuid());

        $this->assertSame(1, $transport->calls);
        $this->assertSame(RefundGatewayAttemptStatus::Ambiguous, $gateway->status);
        $this->assertSame(RefundStatus::Pending, $refund->fresh()->status);
    }

    public function test_controller_redirects_safely_when_refund_configuration_is_missing(): void
    {
        [$refund] = $this->pendingRefund();
        $admin = User::factory()->admin()->create();
        $eventKey = (string) Str::uuid();
        config()->set('services.vnpay.refund_create_by');
        Log::spy();

        $this->actingAs($admin)
            ->from(route('admin.refunds.show', $refund))
            ->post(route('admin.refunds.submit', $refund), ['event_key' => $eventKey])
            ->assertRedirect(route('admin.refunds.show', $refund))
            ->assertSessionHasErrorsIn('submitRefund', ['refund'])
            ->assertSessionHasInput('event_key', $eventKey);

        $this->assertDatabaseCount('refund_gateway_attempts', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode($context, JSON_THROW_ON_ERROR);

            return $message === 'VNPay refund configuration prevented submission.'
                && $context['reason'] === 'configuration_invalid'
                && ! str_contains($encoded, 'refund-feature-secret');
        })->once();
    }

    public function test_controller_turns_transport_failure_into_ambiguous_evidence_without_500_or_retry(): void
    {
        [$refund] = $this->pendingRefund();
        $admin = User::factory()->admin()->create();
        $calls = 0;
        Http::fake(function () use (&$calls): never {
            $calls++;

            throw new ConnectionException('simulated timeout');
        });

        $this->actingAs($admin)
            ->post(route('admin.refunds.submit', $refund), ['event_key' => (string) Str::uuid()])
            ->assertRedirect(route('admin.refunds.show', $refund))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('refund_gateway_attempts', [
            'refund_id' => $refund->id,
            'status' => RefundGatewayAttemptStatus::Ambiguous->value,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'refund.vnpay.submitted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'refund.vnpay.ambiguous']);
        $this->assertSame(1, $calls);

        $this->actingAs($admin)
            ->post(route('admin.refunds.submit', $refund), ['event_key' => (string) Str::uuid()])
            ->assertRedirect(route('admin.refunds.show', $refund));
        $this->assertSame(1, $calls);
    }

    public function test_detail_hides_submit_when_refund_configuration_is_invalid(): void
    {
        [$refund] = $this->pendingRefund();
        $admin = User::factory()->admin()->create();
        config()->set('services.vnpay.refund_create_by');

        $this->actingAs($admin)->get(route('admin.refunds.show', $refund))
            ->assertOk()
            ->assertSee('Cấu hình hoàn tiền chưa hợp lệ.')
            ->assertDontSee('data-confirm-action=', false);
    }

    public function test_success_finalization_rolls_back_each_business_and_audit_boundary(): void
    {
        foreach ([
            ['refunds', 'UPDATE', null],
            ['payment_attempts', 'UPDATE', null],
            ['coupon_usages', 'UPDATE', null],
            ['audit_logs', 'INSERT', "NEW.action = 'refund.vnpay.succeeded'"],
        ] as $index => [$table, $operation, $when]) {
            [$refund, $usage] = $this->pendingRefund(true);
            $admin = User::factory()->admin()->create();
            $transport = new FakeRefundTransport($this->refundResult(RefundGatewayAttemptStatus::Succeeded, '00', '00'));
            $this->app->instance(VnPayRefundTransport::class, $transport);
            $trigger = "test_refund_rollback_{$index}";
            $condition = $when === null ? '' : " WHEN {$when}";
            DB::statement("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table}{$condition} BEGIN SELECT RAISE(ABORT, 'forced rollback'); END");

            try {
                app(SubmitVnPayRefund::class)->handle($refund, $admin, (string) Str::uuid());
                $this->fail("Finalization unexpectedly crossed the {$table} failure boundary.");
            } catch (QueryException) {
                $this->assertSame(RefundStatus::Pending, $refund->fresh()->status);
                $this->assertSame(PaymentStatus::Paid, $refund->paymentAttempt->fresh()->status);
                $this->assertSame(CouponUsageStatus::Consumed, $usage->fresh()->status);
                $this->assertDatabaseHas('refund_gateway_attempts', [
                    'refund_id' => $refund->id,
                    'status' => RefundGatewayAttemptStatus::Submitted->value,
                ]);
                $this->assertDatabaseHas('audit_logs', [
                    'subject_id' => $refund->id,
                    'action' => 'refund.vnpay.submitted',
                ]);
                $this->assertDatabaseMissing('audit_logs', [
                    'subject_id' => $refund->id,
                    'action' => 'refund.vnpay.succeeded',
                ]);
            } finally {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
    }

    public function test_interrupted_submitted_evidence_can_be_marked_ambiguous_without_an_http_retry(): void
    {
        [$refund] = $this->pendingRefund();
        $admin = User::factory()->admin()->create();
        $gateway = RefundGatewayAttempt::factory()->create([
            'refund_id' => $refund->id,
            'submitted_by' => $admin->id,
            'amount_vnd' => $refund->amount_vnd,
        ]);
        $eventKey = (string) Str::uuid();

        try {
            app(MarkSubmittedRefundAmbiguous::class)->handle($refund, $admin, $eventKey, 'Process vẫn có thể đang chạy');
            $this->fail('Fresh submitted evidence was marked ambiguous before the stale lease elapsed.');
        } catch (ValidationException) {
            $this->assertSame(RefundGatewayAttemptStatus::Submitted, $gateway->fresh()->status);
        }

        $this->travel(61)->seconds();

        $first = app(MarkSubmittedRefundAmbiguous::class)->handle($refund, $admin, $eventKey, 'Process dừng sau prepare');
        $replay = app(MarkSubmittedRefundAmbiguous::class)->handle($refund, $admin, $eventKey, 'Process dừng sau prepare');

        $this->assertTrue($first->is($replay));
        $this->assertSame(RefundGatewayAttemptStatus::Ambiguous, $gateway->fresh()->status);
        $this->assertSame(RefundStatus::Pending, $refund->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['request_id' => $eventKey, 'action' => 'refund.vnpay.submission_interrupted']);
    }

    public function test_verified_late_response_can_finalize_the_same_request_after_stale_mark(): void
    {
        [$refund, $usage] = $this->pendingRefund(true);
        $admin = User::factory()->admin()->create();
        $result = $this->refundResult(RefundGatewayAttemptStatus::Succeeded, '00', '00');
        $transport = new class($refund, $admin, $result) implements VnPayRefundTransport
        {
            public function __construct(
                private readonly Refund $refund,
                private readonly User $admin,
                private readonly VnPayRefundResult $result,
            ) {}

            public function send(VnPayRefundRequest $request): VnPayRefundResult
            {
                $attempt = RefundGatewayAttempt::query()->where('request_id', $request->requestId())->firstOrFail();
                CarbonImmutable::setTestNow($attempt->submitted_at->addSeconds(61));
                app(MarkSubmittedRefundAmbiguous::class)->handle(
                    $this->refund,
                    $this->admin,
                    (string) Str::uuid(),
                    'Tiến trình submit quá stale trong khi chờ finalize',
                );

                return new VnPayRefundResult(
                    $this->result->status,
                    $this->result->responseCode,
                    $this->result->transactionStatus,
                    $this->result->gatewayReference,
                    $this->result->responseFingerprint,
                    CarbonImmutable::now('UTC'),
                );
            }
        };
        $this->app->instance(VnPayRefundTransport::class, $transport);

        try {
            $gateway = app(SubmitVnPayRefund::class)->handle($refund, $admin, (string) Str::uuid());
        } finally {
            CarbonImmutable::setTestNow();
        }

        $this->assertSame(RefundGatewayAttemptStatus::Succeeded, $gateway->status);
        $this->assertSame(RefundStatus::Succeeded, $refund->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $refund->paymentAttempt->fresh()->status);
        $this->assertSame(CouponUsageStatus::Released, $usage->fresh()->status);
        $this->assertDatabaseCount('refund_gateway_attempts', 1);
    }

    public function test_only_active_admin_can_submit_or_reconcile_and_http_routes_do_not_accept_business_fields(): void
    {
        [$refund] = $this->pendingRefund();
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create();
        $customer = User::factory()->create();

        $this->get(route('admin.refunds.index'))->assertRedirect(route('login'));
        $this->actingAs($employee)->get(route('admin.refunds.index'))->assertForbidden();
        $this->actingAs($customer)->post(route('admin.refunds.submit', $refund), ['event_key' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($admin)->get(route('admin.refunds.index'))->assertOk()->assertSee('Hoàn tiền VNPay');
        $this->actingAs($admin)->get(route('admin.refunds.show', $refund))->assertOk()->assertSee('Gửi yêu cầu hoàn tiền');

        $this->actingAs($admin)->from(route('admin.refunds.show', $refund))->post(route('admin.refunds.submit', $refund), [
            'event_key' => (string) Str::uuid(),
            'amount' => 1,
            'status' => 'succeeded',
        ])->assertSessionHasErrorsIn('submitRefund', ['request']);
        $this->assertDatabaseCount('refund_gateway_attempts', 0);
    }

    public function test_same_reconciliation_key_with_different_payload_conflicts(): void
    {
        [$refund] = $this->pendingRefund();
        $admin = User::factory()->admin()->create();
        $transport = new FakeRefundTransport($this->refundResult(RefundGatewayAttemptStatus::Ambiguous, '99', '05'));
        $this->app->instance(VnPayRefundTransport::class, $transport);
        app(SubmitVnPayRefund::class)->handle($refund, $admin, (string) Str::uuid());
        $key = (string) Str::uuid();
        app(ReconcileVnPayRefund::class)->handle($refund, $admin, $key, 'failed', 'Không có giao dịch hoàn');

        $this->expectException(ValidationException::class);
        app(ReconcileVnPayRefund::class)->handle($refund->fresh(), $admin, $key, 'failed', 'Ghi chú khác');
    }

    public function test_migration_round_trips_when_empty_and_refuses_to_delete_gateway_evidence(): void
    {
        $migration = require database_path('migrations/2026_10_04_000000_enable_vnpay_refund_processing.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('refund_gateway_attempts'));
        $migration->up();
        $gateway = RefundGatewayAttempt::factory()->create();

        try {
            $migration->down();
            $this->fail('Migration rollback deleted Refund gateway evidence.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('evidence', $exception->getMessage());
        }
        $this->assertDatabaseHas('refund_gateway_attempts', ['id' => $gateway->id]);
    }

    private function pendingRefund(bool $withCoupon = false): array
    {
        $coupon = $withCoupon ? Coupon::factory()->create() : null;
        $attempt = PaymentAttempt::factory()->create(['coupon_id' => $coupon?->id]);
        $attempt->finalizeCallback([
            'status' => PaymentStatus::Paid,
            'gateway_transaction_id' => (string) fake()->numberBetween(100000000, 999999999),
            'gateway_result_code' => '00',
            'gateway_transaction_status' => '00',
            'gateway_paid_at' => now(),
            'callback_fingerprint' => hash('sha256', (string) Str::uuid()),
            'verified_at' => now(),
        ]);
        $order = null;
        $usage = null;
        if ($coupon !== null) {
            $couponSnapshot = [
                'coupon_id' => $coupon->id, 'code' => $coupon->code, 'type' => $coupon->type->value,
                'scope' => $coupon->scope->value, 'value' => $coupon->value,
                'eligible_subtotal_vnd' => $attempt->pricing_snapshot_json['cart_subtotal_vnd'],
            ];
            $order = Order::factory()->forVerifiedAttempt($attempt)->create(['coupon_snapshot_json' => $couponSnapshot]);
            $usage = (new CouponUsage)->forceFill([
                'coupon_id' => $coupon->id,
                'customer_id' => $attempt->user_id,
                'payment_attempt_id' => $attempt->id,
                'order_id' => $order->id,
                'status' => CouponUsageStatus::Consumed,
                'reserved_at' => now()->subMinutes(2),
                'expires_at' => now()->addMinutes(13),
                'consumed_at' => now()->subMinute(),
                'released_at' => null,
                'release_reason' => null,
                'late_callback_exception' => false,
                'created_at' => now()->subMinutes(2),
                'updated_at' => now()->subMinute(),
            ]);
            $usage->save();
        }
        $refund = (new Refund)->forceFill([
            'payment_attempt_id' => $attempt->id,
            'order_id' => $order?->id,
            'amount_vnd' => $attempt->amount_vnd,
            'reason' => RefundReason::StockUnavailable,
            'status' => RefundStatus::Pending,
            'gateway_refund_reference' => null,
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $refund->save();

        return [$refund, $usage];
    }

    private function refundResult(RefundGatewayAttemptStatus $status, ?string $responseCode, ?string $transactionStatus): VnPayRefundResult
    {
        return new VnPayRefundResult(
            $status,
            $responseCode,
            $transactionStatus,
            $status === RefundGatewayAttemptStatus::Ambiguous ? null : '987654321',
            hash('sha256', $status->value.$responseCode.$transactionStatus),
            CarbonImmutable::now('UTC'),
        );
    }
}

class FakeRefundTransport implements VnPayRefundTransport
{
    public int $calls = 0;

    public function __construct(
        private readonly ?VnPayRefundResult $result,
    ) {}

    public function send(VnPayRefundRequest $request): VnPayRefundResult
    {
        $this->calls++;
        if (! $this->result instanceof VnPayRefundResult) {
            throw new LogicException('Fake Refund transport requires a result when it does not throw.');
        }

        return new VnPayRefundResult(
            $this->result->status,
            $this->result->responseCode,
            $this->result->transactionStatus,
            $this->result->gatewayReference,
            $this->result->responseFingerprint,
            CarbonImmutable::now('UTC'),
        );
    }
}
