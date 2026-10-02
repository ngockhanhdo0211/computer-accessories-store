<?php

namespace App\Services;

use App\Exceptions\VnPayGatewayException;
use App\Models\PaymentAttempt;
use Carbon\CarbonInterface;

class VnPayGateway
{
    public const SANDBOX_PAYMENT_URL = 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html';

    public const MAX_AMOUNT_VND = 9_999_999_999;

    /** @return array{payment_url:string,terminal_code:string,hash_secret:string,return_url:string,version:string,timezone:string} */
    public function validatedConfiguration(): array
    {
        $configuration = [
            'payment_url' => config('services.vnpay.payment_url'),
            'terminal_code' => config('services.vnpay.terminal_code'),
            'hash_secret' => config('services.vnpay.hash_secret'),
            'return_url' => config('services.vnpay.return_url'),
            'version' => config('services.vnpay.version'),
            'timezone' => config('services.vnpay.timezone'),
        ];

        foreach ($configuration as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new VnPayGatewayException("Missing VNPay configuration: {$key}.");
            }
            $configuration[$key] = trim($value);
        }

        if ($configuration['payment_url'] !== self::SANDBOX_PAYMENT_URL) {
            throw new VnPayGatewayException('Only the allowlisted VNPay Sandbox payment URL is supported.');
        }
        if ($configuration['version'] !== '2.1.0') {
            throw new VnPayGatewayException('VNPay protocol version must be 2.1.0.');
        }
        if ($configuration['timezone'] !== 'Asia/Ho_Chi_Minh') {
            throw new VnPayGatewayException('VNPay timezone must be Asia/Ho_Chi_Minh.');
        }
        if (preg_match('/^[A-Za-z0-9]{8}$/D', $configuration['terminal_code']) !== 1) {
            throw new VnPayGatewayException('VNPay terminal code must contain exactly eight alphanumeric characters.');
        }
        if (! $this->isValidHttpsUrl($configuration['return_url'])) {
            throw new VnPayGatewayException('VNPay Return URL must be an absolute HTTPS URL without credentials, query or fragment.');
        }

        return $configuration;
    }

    public function isConfigured(): bool
    {
        try {
            $this->validatedConfiguration();

            return true;
        } catch (VnPayGatewayException) {
            return false;
        }
    }

    public function assertAmountCanBeSent(mixed $amountVnd): void
    {
        if (! is_int($amountVnd) || $amountVnd <= 0 || $amountVnd > self::MAX_AMOUNT_VND) {
            throw new VnPayGatewayException('VNPay amount is outside the supported integer range.');
        }
    }

    public function buildPaymentUrl(PaymentAttempt $attempt): string
    {
        $configuration = $this->validatedConfiguration();
        $this->assertAmountCanBeSent($attempt->amount_vnd);

        if (preg_match('/^PA[A-F0-9]{32}$/D', $attempt->gateway_reference) !== 1) {
            throw new VnPayGatewayException('Payment Attempt has an invalid VNPay transaction reference.');
        }
        if (! is_string($attempt->initiated_ip_address)
            || filter_var($attempt->initiated_ip_address, FILTER_VALIDATE_IP) === false
            || strlen($attempt->initiated_ip_address) > 45) {
            throw new VnPayGatewayException('Payment Attempt has no valid initiation IP address.');
        }
        if (! $attempt->created_at instanceof CarbonInterface || ! $attempt->expires_at instanceof CarbonInterface) {
            throw new VnPayGatewayException('Payment Attempt timestamps are incomplete.');
        }
        if ($attempt->expires_at->lessThanOrEqualTo($attempt->created_at)) {
            throw new VnPayGatewayException('Payment Attempt expiry must be after its creation time.');
        }

        $parameters = [
            'vnp_Amount' => (string) ($attempt->amount_vnd * 100),
            'vnp_Command' => 'pay',
            'vnp_CreateDate' => $attempt->created_at->clone()->timezone($configuration['timezone'])->format('YmdHis'),
            'vnp_CurrCode' => 'VND',
            'vnp_ExpireDate' => $attempt->expires_at->clone()->timezone($configuration['timezone'])->format('YmdHis'),
            'vnp_IpAddr' => $attempt->initiated_ip_address,
            'vnp_Locale' => 'vn',
            'vnp_OrderInfo' => 'Thanh toan '.$attempt->gateway_reference,
            'vnp_OrderType' => 'other',
            'vnp_ReturnUrl' => $configuration['return_url'],
            'vnp_TmnCode' => $configuration['terminal_code'],
            'vnp_TxnRef' => $attempt->gateway_reference,
            'vnp_Version' => $configuration['version'],
        ];

        $canonical = $this->canonicalQuery($parameters);
        $signature = hash_hmac('sha512', $canonical, $configuration['hash_secret']);

        $url = $configuration['payment_url'].'?'.$canonical.'&vnp_SecureHash='.$signature;
        if (strlen($url) > 2048 || str_contains($url, "\r") || str_contains($url, "\n")
            || ! str_starts_with($url, self::SANDBOX_PAYMENT_URL.'?')) {
            throw new VnPayGatewayException('Generated VNPay redirect URL is unsafe.');
        }

        return $url;
    }

    /** @param array<string, scalar> $parameters */
    public function canonicalQuery(array $parameters): string
    {
        ksort($parameters, SORT_STRING);

        return http_build_query($parameters, '', '&', PHP_QUERY_RFC1738);
    }

    private function isValidHttpsUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }
}
