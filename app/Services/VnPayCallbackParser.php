<?php

namespace App\Services;

use App\Exceptions\VnPayGatewayException;
use App\Exceptions\VnPayIpnException;
use App\ValueObjects\VnPayCallback;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

class VnPayCallbackParser
{
    private const MAX_QUERY_LENGTH = 4096;

    private const MAX_PARAMETERS = 20;

    private const MAX_VALUE_BYTES = [
        'vnp_TmnCode' => 32,
        'vnp_Amount' => 12,
        'vnp_BankCode' => 40,
        'vnp_BankTranNo' => 100,
        'vnp_CardType' => 40,
        'vnp_OrderInfo' => 1024,
        'vnp_PayDate' => 14,
        'vnp_ResponseCode' => 2,
        'vnp_TxnRef' => 34,
        'vnp_TransactionNo' => 100,
        'vnp_TransactionStatus' => 2,
        'vnp_SecureHash' => 128,
        'vnp_SecureHashType' => 16,
    ];

    private const ALLOWED = [
        'vnp_TmnCode', 'vnp_Amount', 'vnp_BankCode', 'vnp_BankTranNo', 'vnp_CardType',
        'vnp_OrderInfo', 'vnp_PayDate', 'vnp_ResponseCode', 'vnp_TxnRef',
        'vnp_TransactionNo', 'vnp_TransactionStatus', 'vnp_SecureHash', 'vnp_SecureHashType',
    ];

    private const REQUIRED = [
        'vnp_TmnCode', 'vnp_Amount', 'vnp_OrderInfo', 'vnp_PayDate', 'vnp_ResponseCode',
        'vnp_TxnRef', 'vnp_TransactionNo', 'vnp_TransactionStatus', 'vnp_SecureHash',
    ];

    public function __construct(
        private readonly VnPayGateway $gateway,
    ) {}

    public function parse(string $rawQuery): VnPayCallback
    {
        if ($rawQuery === '' || strlen($rawQuery) > self::MAX_QUERY_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $rawQuery) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $rawQuery) === 1) {
            throw new VnPayIpnException('99', 'Invalid callback input.');
        }

        $pairs = explode('&', $rawQuery);
        if ($pairs === [] || count($pairs) > self::MAX_PARAMETERS) {
            throw new VnPayIpnException('99', 'Invalid callback input.');
        }

        $parameters = [];
        foreach ($pairs as $pair) {
            if ($pair === '' || ! str_contains($pair, '=')) {
                throw new VnPayIpnException('99', 'Invalid callback input.');
            }
            [$rawKey, $rawValue] = explode('=', $pair, 2);
            $key = urldecode($rawKey);
            $value = urldecode($rawValue);
            if ($key === '' || str_contains($key, '[') || str_contains($key, ']')
                || ! in_array($key, self::ALLOWED, true) || array_key_exists($key, $parameters)
                || ! mb_check_encoding($key, 'UTF-8') || ! mb_check_encoding($value, 'UTF-8')
                || preg_match('/[\x00-\x1F\x7F]/u', $key) === 1
                || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
                || strlen($value) > self::MAX_VALUE_BYTES[$key]) {
                throw new VnPayIpnException('99', 'Invalid callback input.');
            }
            $parameters[$key] = $value;
        }

        foreach (self::REQUIRED as $required) {
            if (! array_key_exists($required, $parameters) || $parameters[$required] === '') {
                $code = $required === 'vnp_SecureHash' ? '97' : '99';
                throw new VnPayIpnException($code, 'Missing callback field.');
            }
        }

        $signature = $parameters['vnp_SecureHash'];
        if (preg_match('/^[A-Fa-f0-9]{128}$/D', $signature) !== 1
            || (isset($parameters['vnp_SecureHashType']) && strtoupper($parameters['vnp_SecureHashType']) !== 'SHA512')) {
            throw new VnPayIpnException('97', 'Invalid signature.');
        }

        if (preg_match('/^[0-9]{1,12}$/D', $parameters['vnp_Amount']) !== 1
            || preg_match('/^PA[A-F0-9]{32}$/D', $parameters['vnp_TxnRef']) !== 1
            || preg_match('/^[0-9]{2}$/D', $parameters['vnp_ResponseCode']) !== 1
            || preg_match('/^[0-9]{2}$/D', $parameters['vnp_TransactionStatus']) !== 1
            || preg_match('/^[0-9]{1,100}$/D', $parameters['vnp_TransactionNo']) !== 1
            || preg_match('/^[0-9]{14}$/D', $parameters['vnp_PayDate']) !== 1
            || mb_strlen($parameters['vnp_OrderInfo']) > 255) {
            throw new VnPayIpnException('99', 'Invalid callback shape.');
        }
        $bankCode = $parameters['vnp_BankCode'] ?? null;
        if ($bankCode !== null && ($bankCode === '' || preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $bankCode) !== 1)) {
            throw new VnPayIpnException('99', 'Invalid gateway bank code.');
        }
        foreach (['vnp_BankTranNo', 'vnp_CardType'] as $optional) {
            if (isset($parameters[$optional]) && $parameters[$optional] === '') {
                throw new VnPayIpnException('99', 'Invalid optional callback evidence.');
            }
        }

        try {
            $configuration = $this->gateway->validatedConfiguration();
        } catch (VnPayGatewayException) {
            throw new VnPayIpnException('99', 'Gateway configuration unavailable.');
        }
        $signed = array_filter(
            $parameters,
            fn (string $key): bool => ! in_array($key, ['vnp_SecureHash', 'vnp_SecureHashType'], true),
            ARRAY_FILTER_USE_KEY,
        );
        $expected = hash_hmac('sha512', $this->gateway->canonicalQuery($signed), $configuration['hash_secret']);
        if (! hash_equals(strtolower($expected), strtolower($signature))) {
            throw new VnPayIpnException('97', 'Invalid signature.');
        }
        if (! hash_equals($configuration['terminal_code'], $parameters['vnp_TmnCode'])) {
            throw new VnPayIpnException('99', 'Merchant mismatch.');
        }

        $amountGateway = (int) $parameters['vnp_Amount'];
        if ($amountGateway < 100 || $amountGateway % 100 !== 0) {
            throw new VnPayIpnException('99', 'Invalid callback amount.');
        }
        $amountVnd = intdiv($amountGateway, 100);
        try {
            $this->gateway->assertAmountCanBeSent($amountVnd);
        } catch (VnPayGatewayException) {
            throw new VnPayIpnException('99', 'Invalid callback amount.');
        }

        $successful = $parameters['vnp_ResponseCode'] === '00' && $parameters['vnp_TransactionStatus'] === '00';
        $transactionNo = $parameters['vnp_TransactionNo'];
        if ($successful && ltrim($transactionNo, '0') === '') {
            throw new VnPayIpnException('99', 'Invalid gateway transaction number.');
        }

        $date = DateTimeImmutable::createFromFormat('!YmdHis', $parameters['vnp_PayDate'], new DateTimeZone($configuration['timezone']));
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $date->format('YmdHis') !== $parameters['vnp_PayDate']) {
            throw new VnPayIpnException('99', 'Invalid gateway payment time.');
        }

        $fingerprintFields = [];
        foreach (self::ALLOWED as $key) {
            if (! in_array($key, ['vnp_SecureHash', 'vnp_SecureHashType'], true) && array_key_exists($key, $parameters)) {
                $fingerprintFields[$key] = $parameters[$key];
            }
        }

        return new VnPayCallback(
            $parameters['vnp_TmnCode'],
            $amountVnd,
            $parameters['vnp_OrderInfo'],
            $parameters['vnp_TxnRef'],
            $parameters['vnp_ResponseCode'],
            $parameters['vnp_TransactionStatus'],
            $successful ? $transactionNo : null,
            CarbonImmutable::instance($date)->utc(),
            $bankCode,
            $fingerprintFields,
        );
    }
}
