<?php

declare(strict_types=1);

namespace EasyPay\Payment\Providers\WechatPay;

use EasyPay\Payment\Contracts\GatewayInterface;
use EasyPay\Payment\Core\AbstractGateway;
use EasyPay\Payment\Exception\SignatureException;
use EasyPay\Payment\Request\CloseOrder;
use EasyPay\Payment\Request\PayOrder;
use EasyPay\Payment\Request\QueryOrder;
use EasyPay\Payment\Request\RefundOrder;
use EasyPay\Payment\Request\RefundQuery;
use EasyPay\Payment\Response\GatewayResponse;
use EasyPay\Payment\Response\ParsedNotify;
use EasyPay\Payment\Support\AesGcm;
use EasyPay\Payment\Support\Header;
use EasyPay\Payment\Support\Payload;
use EasyPay\Payment\Support\RsaSha256;

final class WechatPayGateway extends AbstractGateway implements GatewayInterface
{
    private const PROVIDER = 'wechatpay';

    public function create(PayOrder $order): GatewayResponse
    {
        $scene = strtolower($order->scene);
        $path = match ($scene) {
            'jsapi' => '/pay/transactions/jsapi',
            'h5', 'wap' => '/pay/transactions/h5',
            'app' => '/pay/transactions/app',
            default => '/pay/transactions/native',
        };

        $payload = [
            'appid' => $this->config()->requireString('app_id'),
            'mchid' => $this->config()->requireString('mch_id'),
            'description' => $order->descriptionText(),
            'out_trade_no' => $order->outTradeNo,
            'notify_url' => $order->notifyUrl,
            'amount' => [
                'total' => $order->amountInMinorUnits(),
                'currency' => $order->currency,
            ],
        ];

        if ($scene === 'jsapi' && $order->openid !== null) {
            $payload['payer'] = ['openid' => $order->openid];
        }

        if (in_array($scene, ['h5', 'wap'], true)) {
            $payload['scene_info'] = Payload::filter([
                'payer_client_ip' => $order->clientIp,
                'h5_info' => ['type' => 'Wap'],
            ]);
        }

        return $this->sendJson(
            operation: 'create',
            method: 'POST',
            path: $path,
            payload: $payload
        );
    }

    public function query(QueryOrder $query): GatewayResponse
    {
        $mchId = rawurlencode($this->config()->requireString('mch_id'));

        if ($query->tradeNo !== null && trim($query->tradeNo) !== '') {
            $path = sprintf('/pay/transactions/id/%s?mchid=%s', rawurlencode($query->tradeNo), $mchId);
        } else {
            $path = sprintf(
                '/pay/transactions/out-trade-no/%s?mchid=%s',
                rawurlencode((string) $query->outTradeNo),
                $mchId
            );
        }

        return $this->sendJson(
            operation: 'query',
            method: 'GET',
            path: $path
        );
    }

    public function close(CloseOrder $order): GatewayResponse
    {
        $path = sprintf('/pay/transactions/out-trade-no/%s/close', rawurlencode($order->outTradeNo));

        return $this->sendJson(
            operation: 'close',
            method: 'POST',
            path: $path,
            payload: ['mchid' => $this->config()->requireString('mch_id')]
        );
    }

    public function refund(RefundOrder $order): GatewayResponse
    {
        $payload = Payload::filter([
            'out_trade_no' => $order->outTradeNo,
            'out_refund_no' => $order->refundNo,
            'reason' => $order->reason,
            'notify_url' => $order->metadata['notify_url'] ?? null,
            'amount' => [
                'refund' => $order->amountInMinorUnits(),
                'total' => $order->metadata['total_amount'] ?? $order->amountInMinorUnits(),
                'currency' => $order->metadata['currency'] ?? 'CNY',
            ],
        ]);

        return $this->sendJson(
            operation: 'refund',
            method: 'POST',
            path: '/refund/domestic/refunds',
            payload: $payload
        );
    }

    public function refundQuery(RefundQuery $query): GatewayResponse
    {
        $path = sprintf('/refund/domestic/refunds/%s', rawurlencode($query->refundNo));

        return $this->sendJson(
            operation: 'refund_query',
            method: 'GET',
            path: $path
        );
    }

    public function parseNotify(string $body, array $headers = []): ParsedNotify
    {
        $headers = Header::normalize($headers);

        $this->verifyNotifySignature($body, $headers);

        $decoded = json_decode($body, true);
        $payload = is_array($decoded) ? $decoded : [];
        $resource = $payload['resource'] ?? [];
        $plain = [];

        if (is_array($resource) && isset($resource['ciphertext'], $resource['nonce'])) {
            $plainText = AesGcm::decrypt(
                (string) $resource['ciphertext'],
                $this->config()->requireString('api_v3_key'),
                (string) $resource['nonce'],
                (string) ($resource['associated_data'] ?? '')
            );

            $plain = json_decode($plainText, true);
            $plain = is_array($plain) ? $plain : [];
        }

        $amount = null;

        if (isset($plain['amount']['payer_total'])) {
            $amount = number_format(((int) $plain['amount']['payer_total']) / 100, 2, '.', '');
        }

        return new ParsedNotify(
            provider: self::PROVIDER,
            event: (string) ($payload['event_type'] ?? 'TRANSACTION.SUCCESS'),
            outTradeNo: (string) ($plain['out_trade_no'] ?? ''),
            tradeNo: $plain['transaction_id'] ?? null,
            status: $plain['trade_state'] ?? null,
            amount: $amount,
            data: $plain,
            raw: [
                'body' => $body,
                'headers' => $headers,
                'payload' => $payload,
            ]
        );
    }

    private function sendJson(
        string $operation,
        string $method,
        string $path,
        ?array $payload = null
    ): GatewayResponse {
        $uri = rtrim($this->config()->get('gateway_uri', 'https://api.mch.weixin.qq.com/v3'), '/') . $path;
        $body = $payload === null ? '' : Payload::json($payload);
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $privateKey = $this->config()->get('private_key');
        $serialNo = $this->config()->get('serial_no');
        $mchId = $this->config()->get('mch_id');

        if (is_string($privateKey) && $privateKey !== ''
            && is_string($serialNo) && $serialNo !== ''
            && is_string($mchId) && $mchId !== '') {
            $headers['Authorization'] = $this->buildAuthorizationHeader(
                $method,
                $path,
                $body,
                $mchId,
                $serialNo,
                $privateKey
            );
        }

        return $this->send(
            provider: self::PROVIDER,
            operation: $operation,
            method: $method,
            uri: $uri,
            payload: $body === '' ? null : $body,
            headers: $headers
        );
    }

    private function buildAuthorizationHeader(
        string $method,
        string $path,
        string $body,
        string $mchId,
        string $serialNo,
        string $privateKey
    ): string {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $message = sprintf(
            "%s\n%s\n%s\n%s\n%s\n",
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            $body
        );

        $signature = RsaSha256::sign($message, $privateKey);

        return sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"',
            $mchId,
            $nonce,
            $signature,
            $timestamp,
            $serialNo
        );
    }

    private function verifyNotifySignature(string $body, array $headers): void
    {
        $signature = $headers['wechatpay-signature'] ?? null;
        $timestamp = $headers['wechatpay-timestamp'] ?? null;
        $nonce = $headers['wechatpay-nonce'] ?? null;

        if (!is_string($signature) || !is_string($timestamp) || !is_string($nonce)) {
            return;
        }

        $serial = $headers['wechatpay-serial'] ?? '';
        $publicKey = $this->config()->get('platform_public_keys.' . $serial)
            ?? $this->config()->get('platform_public_key');

        if (!is_string($publicKey) || $publicKey === '') {
            return;
        }

        $message = sprintf("%s\n%s\n%s\n", $timestamp, $nonce, $body);
        $verified = RsaSha256::verify($message, $signature, $publicKey);

        if (!$verified) {
            throw new SignatureException('Invalid wechatpay notify signature.');
        }
    }
}


