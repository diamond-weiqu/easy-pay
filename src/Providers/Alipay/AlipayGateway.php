<?php

declare(strict_types=1);

namespace EasyPay\Payment\Providers\Alipay;

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
use EasyPay\Payment\Support\AlipayCertificate;
use EasyPay\Payment\Support\Header;
use EasyPay\Payment\Support\KeyValue;
use EasyPay\Payment\Support\Payload;
use EasyPay\Payment\Support\RsaSha256;

final class AlipayGateway extends AbstractGateway implements GatewayInterface
{
    private const PROVIDER = 'alipay';

    public function create(PayOrder $order): GatewayResponse
    {
        $method = match (strtolower($order->scene)) {
            'web', 'page' => 'alipay.trade.page.pay',
            'wap', 'h5' => 'alipay.trade.wap.pay',
            default => 'alipay.trade.precreate',
        };

        $payload = $this->buildPayload($method, [
            'notify_url' => $order->notifyUrl,
            'return_url' => $order->returnUrl,
            'biz_content' => Payload::json([
                'out_trade_no' => $order->outTradeNo,
                'total_amount' => $order->amount,
                'subject' => $order->subject,
                'body' => $order->descriptionText(),
                'product_code' => match ($method) {
                    'alipay.trade.page.pay' => 'FAST_INSTANT_TRADE_PAY',
                    'alipay.trade.wap.pay' => 'QUICK_WAP_WAY',
                    default => 'FACE_TO_FACE_PAYMENT',
                },
            ]),
        ]);

        return $this->send(
            provider: self::PROVIDER,
            operation: 'create',
            method: 'POST',
            uri: $this->gatewayUri(),
            payload: $payload,
            headers: ['Content-Type' => 'application/x-www-form-urlencoded']
        );
    }

    public function query(QueryOrder $query): GatewayResponse
    {
        $payload = $this->buildPayload('alipay.trade.query', [
            'biz_content' => Payload::json([
                'out_trade_no' => $query->outTradeNo,
                'trade_no' => $query->tradeNo,
            ]),
        ]);

        return $this->send(
            provider: self::PROVIDER,
            operation: 'query',
            method: 'POST',
            uri: $this->gatewayUri(),
            payload: $payload,
            headers: ['Content-Type' => 'application/x-www-form-urlencoded']
        );
    }

    public function close(CloseOrder $order): GatewayResponse
    {
        $payload = $this->buildPayload('alipay.trade.close', [
            'biz_content' => Payload::json([
                'out_trade_no' => $order->outTradeNo,
            ]),
        ]);

        return $this->send(
            provider: self::PROVIDER,
            operation: 'close',
            method: 'POST',
            uri: $this->gatewayUri(),
            payload: $payload,
            headers: ['Content-Type' => 'application/x-www-form-urlencoded']
        );
    }

    public function refund(RefundOrder $order): GatewayResponse
    {
        $payload = $this->buildPayload('alipay.trade.refund', [
            'biz_content' => Payload::json([
                'out_trade_no' => $order->outTradeNo,
                'refund_amount' => $order->amount,
                'out_request_no' => $order->refundNo,
                'refund_reason' => $order->reason,
            ]),
        ]);

        return $this->send(
            provider: self::PROVIDER,
            operation: 'refund',
            method: 'POST',
            uri: $this->gatewayUri(),
            payload: $payload,
            headers: ['Content-Type' => 'application/x-www-form-urlencoded']
        );
    }

    public function refundQuery(RefundQuery $query): GatewayResponse
    {
        $payload = $this->buildPayload('alipay.trade.fastpay.refund.query', [
            'biz_content' => Payload::json([
                'out_trade_no' => $query->outTradeNo,
                'out_request_no' => $query->refundNo,
            ]),
        ]);

        return $this->send(
            provider: self::PROVIDER,
            operation: 'refund_query',
            method: 'POST',
            uri: $this->gatewayUri(),
            payload: $payload,
            headers: ['Content-Type' => 'application/x-www-form-urlencoded']
        );
    }

    public function parseNotify(string $body, array $headers = []): ParsedNotify
    {
        $headers = Header::normalize($headers);
        $data = $this->decodeNotifyBody($body, $headers);

        $sign = $data['sign'] ?? null;
        $publicKey = $this->notifyPublicKey();

        if (is_string($sign) && $publicKey !== null) {
            $verified = RsaSha256::verify(
                Payload::queryString($data, ['sign', 'sign_type']),
                $sign,
                $publicKey
            );

            if (!$verified) {
                throw new SignatureException('Invalid alipay notify signature.');
            }
        }

        return new ParsedNotify(
            provider: self::PROVIDER,
            event: (string) ($data['notify_type'] ?? 'trade_notify'),
            outTradeNo: (string) ($data['out_trade_no'] ?? ''),
            tradeNo: $data['trade_no'] ?? null,
            status: $data['trade_status'] ?? null,
            amount: $data['buyer_pay_amount'] ?? ($data['total_amount'] ?? null),
            data: $data,
            raw: [
                'body' => $body,
                'headers' => $headers,
            ]
        );
    }

    private function gatewayUri(): string
    {
        return $this->config()->get('gateway_uri', 'https://openapi.alipay.com/gateway.do');
    }

    private function buildPayload(string $method, array $extra): string
    {
        $payload = Payload::filter(array_merge([
            'app_id' => $this->config()->requireString('app_id'),
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => $this->config()->get('sign_type', 'RSA2'),
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
        ], $extra, $this->certificatePayload()));

        $privateKey = KeyValue::resolve($this->config()->get('private_key'));

        if (is_string($privateKey) && $privateKey !== '') {
            $payload['sign'] = RsaSha256::sign(
                Payload::queryString($payload, ['sign']),
                $privateKey
            );
        }

        return http_build_query($payload);
    }

    private function certificatePayload(): array
    {
        if (!$this->certificateMode()) {
            return [];
        }

        $appCertSn = $this->config()->get('app_cert_sn');
        if (!is_string($appCertSn) || trim($appCertSn) === '') {
            $appCertSn = AlipayCertificate::appCertSerialNumber($this->config()->get('app_cert'));
        }

        $rootCertSn = $this->config()->get('alipay_root_cert_sn');
        if (!is_string($rootCertSn) || trim($rootCertSn) === '') {
            $rootCertSn = AlipayCertificate::rootCertSerialNumber($this->config()->get('alipay_root_cert'));
        }

        return [
            'app_cert_sn' => $appCertSn,
            'alipay_root_cert_sn' => $rootCertSn,
        ];
    }

    private function certificateMode(): bool
    {
        return $this->config()->has('app_cert')
            || $this->config()->has('app_cert_sn')
            || $this->config()->has('alipay_root_cert')
            || $this->config()->has('alipay_root_cert_sn');
    }

    private function notifyPublicKey(): ?string
    {
        $publicKey = KeyValue::resolve($this->config()->get('public_key'));
        if (is_string($publicKey) && $publicKey !== '') {
            return $publicKey;
        }

        if ($this->config()->has('alipay_public_cert')) {
            return AlipayCertificate::publicKey($this->config()->get('alipay_public_cert'), 'alipay_public_cert');
        }

        return null;
    }

    private function decodeNotifyBody(string $body, array $headers): array
    {
        $contentType = strtolower($headers['content-type'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : [];
        }

        parse_str($body, $data);

        return is_array($data) ? $data : [];
    }
}
