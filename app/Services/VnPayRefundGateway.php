<?php

namespace App\Services;

use App\Contracts\VnPayRefundTransport;
use App\Enums\RefundGatewayAttemptStatus;
use App\Exceptions\VnPayGatewayException;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\ValueObjects\VnPayRefundRequest;
use App\ValueObjects\VnPayRefundResult;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class VnPayRefundGateway implements VnPayRefundTransport
{
    public const SANDBOX_REFUND_URL = 'https://sandbox.vnpayment.vn/merchant_webapi/api/transaction';

    private const REQUEST_SIGNATURE_FIELDS = [
        'vnp_RequestId', 'vnp_Version', 'vnp_Command', 'vnp_TmnCode', 'vnp_TransactionType',
        'vnp_TxnRef', 'vnp_Amount', 'vnp_TransactionNo', 'vnp_TransactionDate', 'vnp_CreateBy',
        'vnp_CreateDate', 'vnp_IpAddr', 'vnp_OrderInfo',
    ];

    private const RESPONSE_SIGNATURE_FIELDS = [
        'vnp_ResponseId', 'vnp_Command', 'vnp_ResponseCode', 'vnp_Message', 'vnp_TmnCode',
        'vnp_TxnRef', 'vnp_Amount', 'vnp_BankCode', 'vnp_PayDate', 'vnp_TransactionNo',
        'vnp_TransactionType', 'vnp_TransactionStatus', 'vnp_OrderInfo',
    ];

    private const DEFINITIVE_FAILURE_CODES = ['02', '03', '04', '13', '16', '91', '93', '95', '97'];

    private const AMBIGUOUS_RESPONSE_CODES = ['05', '06', '94', '98', '99'];

    public function buildRequest(
        Refund $refund,
        PaymentAttempt $paymentAttempt,
        string $requestId,
        CarbonInterface $submittedAt,
    ): VnPayRefundRequest {
        $configuration = $this->validatedConfiguration();
        if (preg_match('/^RF[0-9A-F]{30}$/D', $requestId) !== 1) {
            throw new VnPayGatewayException('VNPay Refund Request ID is invalid.');
        }
        if ($refund->amount_vnd < 1 || $refund->amount_vnd > intdiv(PHP_INT_MAX, 100)) {
            throw new VnPayGatewayException('VNPay Refund amount is outside the supported integer range.');
        }
        if ($refund->payment_attempt_id !== $paymentAttempt->id
            || $refund->amount_vnd !== $paymentAttempt->amount_vnd
            || ! is_string($paymentAttempt->gateway_reference)
            || preg_match('/^[A-Za-z0-9]{1,100}$/D', $paymentAttempt->gateway_reference) !== 1
            || ! is_string($paymentAttempt->gateway_transaction_id)
            || preg_match('/^[0-9]{1,15}$/D', $paymentAttempt->gateway_transaction_id) !== 1
            || $paymentAttempt->created_at === null) {
            throw new VnPayGatewayException('Payment Attempt has incomplete verified evidence for Refund.');
        }

        $timezone = $configuration['timezone'];
        $parameters = [
            'vnp_RequestId' => $requestId,
            'vnp_Version' => $configuration['version'],
            'vnp_Command' => 'refund',
            'vnp_TmnCode' => $configuration['terminal_code'],
            'vnp_TransactionType' => '02',
            'vnp_TxnRef' => $paymentAttempt->gateway_reference,
            'vnp_Amount' => (string) ($refund->amount_vnd * 100),
            'vnp_TransactionNo' => $paymentAttempt->gateway_transaction_id,
            'vnp_TransactionDate' => $paymentAttempt->created_at->clone()->timezone($timezone)->format('YmdHis'),
            'vnp_CreateBy' => $configuration['refund_create_by'],
            'vnp_CreateDate' => $submittedAt->clone()->timezone($timezone)->format('YmdHis'),
            'vnp_IpAddr' => $configuration['refund_ip_address'],
            'vnp_OrderInfo' => 'Refund '.$paymentAttempt->gateway_reference,
        ];
        $signatureData = $this->signatureData($parameters, self::REQUEST_SIGNATURE_FIELDS);
        $parameters['vnp_SecureHash'] = hash_hmac('sha512', $signatureData, $configuration['hash_secret']);

        return new VnPayRefundRequest($parameters, hash('sha256', $signatureData));
    }

    public function send(VnPayRefundRequest $request): VnPayRefundResult
    {
        $configuration = $this->validatedConfiguration();

        try {
            $response = Http::asJson()->acceptJson()
                ->connectTimeout($configuration['refund_connect_timeout'])
                ->timeout($configuration['refund_timeout'])
                ->withoutRedirecting()
                ->post($configuration['refund_url'], $request->parameters);
        } catch (Throwable) {
            return VnPayRefundResult::ambiguous();
        }

        return $this->parseResponse($response, $request, $configuration);
    }

    public function validatedConfiguration(): array
    {
        $configuration = [
            'refund_url' => config('services.vnpay.refund_url'),
            'terminal_code' => config('services.vnpay.terminal_code'),
            'hash_secret' => config('services.vnpay.hash_secret'),
            'version' => config('services.vnpay.version'),
            'timezone' => config('services.vnpay.timezone'),
            'refund_create_by' => config('services.vnpay.refund_create_by'),
            'refund_ip_address' => config('services.vnpay.refund_ip_address'),
            'refund_connect_timeout' => config('services.vnpay.refund_connect_timeout'),
            'refund_timeout' => config('services.vnpay.refund_timeout'),
        ];
        foreach (['refund_url', 'terminal_code', 'hash_secret', 'version', 'timezone', 'refund_create_by', 'refund_ip_address'] as $key) {
            if (! is_string($configuration[$key]) || trim($configuration[$key]) === '') {
                throw new VnPayGatewayException("Missing VNPay Refund configuration: {$key}.");
            }
            $configuration[$key] = trim($configuration[$key]);
        }
        if ($configuration['refund_url'] !== self::SANDBOX_REFUND_URL
            || $configuration['version'] !== '2.1.0'
            || $configuration['timezone'] !== 'Asia/Ho_Chi_Minh') {
            throw new VnPayGatewayException('Only the allowlisted VNPay Sandbox Refund protocol is supported.');
        }
        if (preg_match('/^[A-Za-z0-9]{8}$/D', $configuration['terminal_code']) !== 1
            || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $configuration['refund_create_by']) !== 1
            || filter_var($configuration['refund_ip_address'], FILTER_VALIDATE_IP) === false
            || strlen($configuration['refund_ip_address']) > 45) {
            throw new VnPayGatewayException('VNPay Refund identity or server IP configuration is invalid.');
        }
        foreach (['refund_connect_timeout', 'refund_timeout'] as $key) {
            if (! is_int($configuration[$key]) || $configuration[$key] < 1 || $configuration[$key] > 30) {
                throw new VnPayGatewayException("VNPay {$key} must be an integer from 1 to 30 seconds.");
            }
        }
        if ($configuration['refund_connect_timeout'] > $configuration['refund_timeout']) {
            throw new VnPayGatewayException('VNPay Refund connect timeout cannot exceed total timeout.');
        }

        return $configuration;
    }

    private function parseResponse(Response $response, VnPayRefundRequest $request, array $configuration): VnPayRefundResult
    {
        $body = $response->body();
        $fingerprint = hash('sha256', $body);
        if (! $response->successful() || strlen($body) > 32_768) {
            return VnPayRefundResult::ambiguous($fingerprint);
        }

        try {
            $payload = $response->json();
        } catch (Throwable) {
            return VnPayRefundResult::ambiguous($fingerprint);
        }
        if (! is_array($payload)) {
            return VnPayRefundResult::ambiguous($fingerprint);
        }

        $normalized = [];
        foreach ([...self::RESPONSE_SIGNATURE_FIELDS, 'vnp_SecureHash'] as $field) {
            $value = $payload[$field] ?? null;
            if (! is_string($value) || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                return VnPayRefundResult::ambiguous($fingerprint);
            }
            $normalized[$field] = $value;
        }
        $expected = hash_hmac('sha512', $this->signatureData($normalized, self::RESPONSE_SIGNATURE_FIELDS), $configuration['hash_secret']);
        if (preg_match('/^[0-9a-fA-F]{128}$/D', $normalized['vnp_SecureHash']) !== 1
            || ! hash_equals($expected, strtolower($normalized['vnp_SecureHash']))) {
            return VnPayRefundResult::ambiguous($fingerprint);
        }

        $sent = $request->parameters;
        if ($normalized['vnp_ResponseId'] !== $request->requestId()
            || $normalized['vnp_Command'] !== 'refund'
            || $normalized['vnp_TmnCode'] !== $configuration['terminal_code']
            || $normalized['vnp_TxnRef'] !== $sent['vnp_TxnRef']
            || $normalized['vnp_Amount'] !== $sent['vnp_Amount']
            || $normalized['vnp_TransactionType'] !== '02'
            || preg_match('/^[0-9]{2}$/D', $normalized['vnp_ResponseCode']) !== 1
            || preg_match('/^[0-9]{2}$/D', $normalized['vnp_TransactionStatus']) !== 1
            || preg_match('/^[0-9]{1,15}$/D', $normalized['vnp_TransactionNo']) !== 1) {
            return VnPayRefundResult::ambiguous($fingerprint);
        }

        $status = RefundGatewayAttemptStatus::Ambiguous;
        if ($normalized['vnp_ResponseCode'] === '00' && $normalized['vnp_TransactionStatus'] === '00') {
            $status = RefundGatewayAttemptStatus::Succeeded;
        } elseif (! in_array($normalized['vnp_ResponseCode'], self::AMBIGUOUS_RESPONSE_CODES, true)
            && (in_array($normalized['vnp_ResponseCode'], self::DEFINITIVE_FAILURE_CODES, true)
                || ($normalized['vnp_ResponseCode'] === '00' && $normalized['vnp_TransactionStatus'] === '09'))) {
            $status = RefundGatewayAttemptStatus::Failed;
        }

        return new VnPayRefundResult(
            $status,
            $normalized['vnp_ResponseCode'],
            $normalized['vnp_TransactionStatus'],
            $normalized['vnp_TransactionNo'],
            $fingerprint,
            CarbonImmutable::now('UTC'),
        );
    }

    private function signatureData(array $parameters, array $fields): string
    {
        return implode('|', array_map(static fn (string $field): string => $parameters[$field] ?? '', $fields));
    }
}
