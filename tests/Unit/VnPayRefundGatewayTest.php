<?php

namespace Tests\Unit;

use App\Enums\RefundGatewayAttemptStatus;
use App\Exceptions\VnPayGatewayException;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Services\VnPayRefundGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VnPayRefundGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.vnpay', [
            'payment_url' => 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html',
            'refund_url' => VnPayRefundGateway::SANDBOX_REFUND_URL,
            'terminal_code' => 'ABCDEFGH',
            'hash_secret' => 'fixed-test-secret',
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

    public function test_fixed_request_vector_uses_full_refund_protocol_and_no_sensitive_identity(): void
    {
        [$refund, $payment] = $this->evidence();
        $request = app(VnPayRefundGateway::class)->buildRequest(
            $refund,
            $payment,
            'RF550E8400E29B41D4A7164466554400',
            CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'),
        );

        $this->assertSame('02', $request->parameters['vnp_TransactionType']);
        $this->assertSame('10000000', $request->parameters['vnp_Amount']);
        $this->assertSame('20261003120000', $request->parameters['vnp_TransactionDate']);
        $this->assertSame('20261003130000', $request->parameters['vnp_CreateDate']);
        $this->assertSame('5074929c4d69612411b7121e684a6e0339685a6e3aa69740dc7275017ed09417071e7bd186b24f3d37e494a3d42c4f08634bbdfaae1d3bc0a2978a0570173adc', $request->parameters['vnp_SecureHash']);
        $this->assertSame('f81809d514cc7c3cadf9d1b157b6b0e1d203ba0381a7847a142ca17bc8a5f0fc', $request->requestFingerprint);
        $this->assertStringNotContainsString('fixed-test-secret', json_encode($request->parameters, JSON_THROW_ON_ERROR));
    }

    public function test_verified_00_00_is_success_and_documented_non_terminal_codes_are_ambiguous(): void
    {
        [$refund, $payment] = $this->evidence();
        $gateway = app(VnPayRefundGateway::class);
        $request = $gateway->buildRequest($refund, $payment, 'RF550E8400E29B41D4A7164466554400', now());

        $cases = [
            ['00', '00', RefundGatewayAttemptStatus::Succeeded],
            ['00', '05', RefundGatewayAttemptStatus::Ambiguous],
            ['00', '06', RefundGatewayAttemptStatus::Ambiguous],
            ['94', '05', RefundGatewayAttemptStatus::Ambiguous],
            ['98', '05', RefundGatewayAttemptStatus::Ambiguous],
            ['99', '05', RefundGatewayAttemptStatus::Ambiguous],
            ['95', '09', RefundGatewayAttemptStatus::Failed],
            ['00', '09', RefundGatewayAttemptStatus::Failed],
            ['99', '09', RefundGatewayAttemptStatus::Ambiguous],
            ['77', '09', RefundGatewayAttemptStatus::Ambiguous],
        ];
        $sequence = Http::fakeSequence();
        foreach ($cases as [$responseCode, $transactionStatus]) {
            $sequence->push($this->signedResponse($request->parameters, $responseCode, $transactionStatus), 200);
        }
        foreach ($cases as [, , $expected]) {
            $this->assertSame($expected, $gateway->send($request)->status);
        }
    }

    public function test_transport_timeout_invalid_signature_and_malformed_response_are_ambiguous(): void
    {
        [$refund, $payment] = $this->evidence();
        $gateway = app(VnPayRefundGateway::class);
        $request = $gateway->buildRequest($refund, $payment, 'RF550E8400E29B41D4A7164466554400', now());

        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->assertSame(RefundGatewayAttemptStatus::Ambiguous, $gateway->send($request)->status);

        $invalid = $this->signedResponse($request->parameters, '00', '00');
        $invalid['vnp_SecureHash'] = str_repeat('0', 128);
        Http::fake(fn () => Http::response($invalid, 200));
        $this->assertSame(RefundGatewayAttemptStatus::Ambiguous, $gateway->send($request)->status);

        Http::fake(fn () => Http::response('not-json', 200));
        $this->assertSame(RefundGatewayAttemptStatus::Ambiguous, $gateway->send($request)->status);

        $mismatchedRequest = $this->signedResponse($request->parameters, '00', '00');
        $mismatchedRequest['vnp_ResponseId'] = 'RF'.str_repeat('A', 30);
        $mismatchedRequest['vnp_SecureHash'] = hash_hmac(
            'sha512',
            implode('|', array_values(array_diff_key($mismatchedRequest, ['vnp_SecureHash' => true]))),
            'fixed-test-secret',
        );
        Http::fake(fn () => Http::response($mismatchedRequest, 200));
        $this->assertSame(RefundGatewayAttemptStatus::Ambiguous, $gateway->send($request)->status);
    }

    public function test_refund_configuration_fails_closed_for_url_identity_ip_and_timeouts(): void
    {
        $gateway = app(VnPayRefundGateway::class);
        foreach ([
            ['refund_url', 'https://sandbox.vnpayment.vn.evil.test/merchant_webapi/api/transaction'],
            ['refund_create_by', ''],
            ['refund_ip_address', 'not-an-ip'],
            ['refund_timeout', 0],
        ] as [$key, $value]) {
            $original = config('services.vnpay.'.$key);
            config()->set('services.vnpay.'.$key, $value);
            try {
                $gateway->validatedConfiguration();
                $this->fail("Invalid {$key} was accepted.");
            } catch (VnPayGatewayException) {
                $this->assertTrue(true);
            } finally {
                config()->set('services.vnpay.'.$key, $original);
            }
        }
    }

    private function evidence(): array
    {
        $payment = new PaymentAttempt;
        $payment->forceFill([
            'id' => 10,
            'gateway_reference' => 'PA550E8400E29B41D4A716446655440000',
            'gateway_transaction_id' => '123456789',
            'amount_vnd' => 100_000,
            'created_at' => CarbonImmutable::parse('2026-10-03 05:00:00', 'UTC'),
        ]);
        $refund = new Refund;
        $refund->forceFill(['id' => 20, 'payment_attempt_id' => 10, 'amount_vnd' => 100_000]);

        return [$refund, $payment];
    }

    private function signedResponse(array $request, string $responseCode, string $transactionStatus): array
    {
        $response = [
            'vnp_ResponseId' => $request['vnp_RequestId'],
            'vnp_Command' => 'refund',
            'vnp_ResponseCode' => $responseCode,
            'vnp_Message' => 'Refund response',
            'vnp_TmnCode' => 'ABCDEFGH',
            'vnp_TxnRef' => $request['vnp_TxnRef'],
            'vnp_Amount' => $request['vnp_Amount'],
            'vnp_BankCode' => 'NCB',
            'vnp_PayDate' => '20261003130100',
            'vnp_TransactionNo' => '987654321',
            'vnp_TransactionType' => '02',
            'vnp_TransactionStatus' => $transactionStatus,
            'vnp_OrderInfo' => $request['vnp_OrderInfo'],
        ];
        $response['vnp_SecureHash'] = hash_hmac('sha512', implode('|', array_values($response)), 'fixed-test-secret');

        return $response;
    }
}
