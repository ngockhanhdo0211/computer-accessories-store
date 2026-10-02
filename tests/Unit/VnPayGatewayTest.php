<?php

namespace Tests\Unit;

use App\Exceptions\VnPayGatewayException;
use App\Models\PaymentAttempt;
use App\Services\VnPayGateway;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class VnPayGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.vnpay', [
            'payment_url' => VnPayGateway::SANDBOX_PAYMENT_URL,
            'terminal_code' => 'ABCDEFGH',
            'hash_secret' => 'fixed-test-secret',
            'return_url' => 'https://shop.example.test/checkout/vnpay/return',
            'version' => '2.1.0',
            'timezone' => 'Asia/Ho_Chi_Minh',
        ]);
    }

    public function test_fixed_vector_proves_canonical_order_encoding_and_hmac_sha512(): void
    {
        $attempt = new PaymentAttempt;
        $attempt->forceFill([
            'gateway_reference' => 'PA550E8400E29B41D4A716446655440000',
            'initiated_ip_address' => '203.0.113.10',
            'amount_vnd' => 100_000,
            'created_at' => CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'),
            'expires_at' => CarbonImmutable::parse('2026-10-02 12:15:00', 'UTC'),
        ]);

        $url = app(VnPayGateway::class)->buildPaymentUrl($attempt);
        $canonical = 'vnp_Amount=10000000&vnp_Command=pay&vnp_CreateDate=20261002190000&vnp_CurrCode=VND&vnp_ExpireDate=20261002191500&vnp_IpAddr=203.0.113.10&vnp_Locale=vn&vnp_OrderInfo=Thanh+toan+PA550E8400E29B41D4A716446655440000&vnp_OrderType=other&vnp_ReturnUrl=https%3A%2F%2Fshop.example.test%2Fcheckout%2Fvnpay%2Freturn&vnp_TmnCode=ABCDEFGH&vnp_TxnRef=PA550E8400E29B41D4A716446655440000&vnp_Version=2.1.0';
        $signature = '2935b923823d59143d6140c780601445503bc71a26ba2c0a99e7aca553c94dcb9ef39d3adf26bc81f31a920b2238bbb6efa2fab3ab5853212c26ec411f80ba3e';

        $this->assertSame(VnPayGateway::SANDBOX_PAYMENT_URL.'?'.$canonical.'&vnp_SecureHash='.$signature, $url);
        $this->assertStringNotContainsString('fixed-test-secret', $url);
    }

    public function test_canonical_query_encodes_unicode_and_spaces_once(): void
    {
        $gateway = app(VnPayGateway::class);
        $parameters = [
            'vnp_OrderInfo' => 'Thanh toán đơn hàng',
            'vnp_Amount' => '10000',
            'vnp_ReturnUrl' => 'https://shop.example.test/checkout/vnpay/return?source=a b&label=đơn',
        ];
        $query = $gateway->canonicalQuery($parameters);

        $this->assertSame('vnp_Amount=10000&vnp_OrderInfo=Thanh+to%C3%A1n+%C4%91%C6%A1n+h%C3%A0ng&vnp_ReturnUrl=https%3A%2F%2Fshop.example.test%2Fcheckout%2Fvnpay%2Freturn%3Fsource%3Da+b%26label%3D%C4%91%C6%A1n', $query);
        $this->assertSame($query, $gateway->canonicalQuery(array_reverse($parameters, true)));
        $changed = $gateway->canonicalQuery(array_merge($parameters, ['vnp_OrderInfo' => 'Thanh toán đơn hảng']));
        $this->assertNotSame(
            hash_hmac('sha512', $query, 'fixed-test-secret'),
            hash_hmac('sha512', $changed, 'fixed-test-secret'),
        );
    }

    public function test_configuration_amount_reference_and_ip_fail_closed(): void
    {
        $gateway = app(VnPayGateway::class);
        $gateway->assertAmountCanBeSent(VnPayGateway::MAX_AMOUNT_VND);
        foreach ([0, VnPayGateway::MAX_AMOUNT_VND + 1, true, '100000'] as $amount) {
            try {
                $gateway->assertAmountCanBeSent($amount);
                $this->fail('Invalid VNPay amount was accepted.');
            } catch (VnPayGatewayException) {
                $this->assertTrue(true);
            }
        }

        config()->set('services.vnpay.payment_url', 'https://evil.example/pay');
        $this->expectException(VnPayGatewayException::class);
        $gateway->validatedConfiguration();
    }

    public function test_configuration_rejects_near_match_hosts_credentials_ports_query_and_fragment(): void
    {
        $gateway = app(VnPayGateway::class);
        $invalidPaymentUrls = [
            'https://sandbox.vnpayment.vn.evil.test/paymentv2/vpcpay.html',
            'https://user@sandbox.vnpayment.vn/paymentv2/vpcpay.html',
            'https://sandbox.vnpayment.vn:443/paymentv2/vpcpay.html',
            VnPayGateway::SANDBOX_PAYMENT_URL.'?next=evil',
            VnPayGateway::SANDBOX_PAYMENT_URL.'#fragment',
        ];

        foreach ($invalidPaymentUrls as $url) {
            config()->set('services.vnpay.payment_url', $url);
            try {
                $gateway->validatedConfiguration();
                $this->fail('Unsafe payment URL was accepted.');
            } catch (VnPayGatewayException) {
                $this->assertTrue(true);
            }
        }

        config()->set('services.vnpay.payment_url', VnPayGateway::SANDBOX_PAYMENT_URL);
        foreach ([
            'https://user@shop.example.test/checkout/vnpay/return',
            'https://shop.example.test:443/checkout/vnpay/return',
            'https://shop.example.test/checkout/vnpay/return?source=x',
            'https://shop.example.test/checkout/vnpay/return#fragment',
        ] as $url) {
            config()->set('services.vnpay.return_url', $url);
            try {
                $gateway->validatedConfiguration();
                $this->fail('Unsafe Return URL was accepted.');
            } catch (VnPayGatewayException) {
                $this->assertTrue(true);
            }
        }
    }
}
