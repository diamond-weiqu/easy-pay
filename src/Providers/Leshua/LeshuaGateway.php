<?php

declare(strict_types=1);

namespace EasyPay\Payment\Providers\Leshua;

use EasyPay\Payment\Contracts\GatewayInterface;
use EasyPay\Payment\Core\AbstractGateway;
use EasyPay\Payment\Request\CloseOrder;
use EasyPay\Payment\Request\PayOrder;
use EasyPay\Payment\Request\QueryOrder;
use EasyPay\Payment\Request\RefundOrder;
use EasyPay\Payment\Request\RefundQuery;
use EasyPay\Payment\Response\GatewayResponse;
use EasyPay\Payment\Response\ParsedNotify;
use EasyPay\Payment\Support\Header;
use EasyPay\Payment\Support\Payload;
use EasyPay\Payment\Support\Xml;

final class LeshuaGateway extends AbstractGateway implements GatewayInterface
{
    private const PROVIDER = 'leshua';
    private const GATEWAY_URI = 'https://paygate.leshuazf.com/cgi-bin/lepos_pay_gateway.cgi';

    public function create(PayOrder $order): GatewayResponse
    {
        $channel = strtolower((string) ($order->metadata['channel'] ?? $order->metadata['payment_channel'] ?? ''));

        if ($channel === '') {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: 'create',
                request: [],
                message: 'Leshua create requires metadata.channel: alipay, wxpay, or bank.'
            );
        }

        $params = [
            'service' => 'get_tdcode',
            'jspay_flag' => $order->metadata['jspay_flag'] ?? $this->defaultJspayFlag($channel, $order->scene),
            'pay_way' => $order->metadata['pay_way'] ?? $this->defaultPayWay($channel),
            'merchant_id' => $this->merchantId(),
            'third_order_id' => $order->outTradeNo,
            'amount' => (string) $order->amountInMinorUnits(),
            'body' => $order->descriptionText(),
            'notify_url' => $order->notifyUrl ?: $this->config()->get('notify_url'),
            'client_ip' => $order->clientIp ?: $this->config()->get('client_ip', '127.0.0.1'),
            'nonce_str' => $order->metadata['nonce_str'] ?? bin2hex(random_bytes(8)),
        ];

        if ($order->openid !== null) {
            $params['sub_openid'] = $order->openid;
        }

        $subAppId = $order->metadata['sub_appid'] ?? $this->config()->get('sub_appid');
        if (is_string($subAppId) && $subAppId !== '') {
            $params['appid'] = $subAppId;
        }

        $params['sign'] = $this->makeSign($params, $this->tradeKey());

        return $this->requestForm('create', $params);
    }

    public function query(QueryOrder $query): GatewayResponse
    {
        return GatewayResponse::failure(
            provider: self::PROVIDER,
            operation: 'query',
            request: ['out_trade_no' => $query->outTradeNo, 'trade_no' => $query->tradeNo],
            message: 'Leshua query is not implemented in the current version.'
        );
    }

    public function close(CloseOrder $order): GatewayResponse
    {
        return GatewayResponse::failure(
            provider: self::PROVIDER,
            operation: 'close',
            request: ['out_trade_no' => $order->outTradeNo],
            message: 'Leshua close is not implemented in the current version.'
        );
    }

    public function refund(RefundOrder $order): GatewayResponse
    {
        $params = [
            'service' => 'unified_refund',
            'merchant_id' => $this->merchantId(),
            'leshua_order_id' => $order->metadata['trade_no'] ?? $order->metadata['leshua_order_id'] ?? '',
            'merchant_refund_id' => $order->refundNo,
            'refund_amount' => (string) $order->amountInMinorUnits(),
            'nonce_str' => $order->metadata['nonce_str'] ?? bin2hex(random_bytes(8)),
        ];

        if ($params['leshua_order_id'] === '') {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: 'refund',
                request: $params,
                message: 'Leshua refund requires metadata.trade_no or metadata.leshua_order_id.'
            );
        }

        $params['sign'] = $this->makeSign($params, $this->tradeKey());

        return $this->requestForm('refund', $params);
    }

    public function refundQuery(RefundQuery $query): GatewayResponse
    {
        return GatewayResponse::failure(
            provider: self::PROVIDER,
            operation: 'refund_query',
            request: ['refund_no' => $query->refundNo, 'out_trade_no' => $query->outTradeNo],
            message: 'Leshua refund query is not implemented in the current version.'
        );
    }

    public function parseNotify(string $body, array $headers = []): ParsedNotify
    {
        $headers = Header::normalize($headers);
        $data = Xml::decode($body);

        $expected = strtolower($this->makeSign($data, $this->notifyKey()));
        $actual = strtolower((string) ($data['sign'] ?? ''));
        $verified = $actual !== '' && hash_equals($expected, $actual);

        $status = (string) ($data['status'] ?? '');
        $amount = isset($data['account']) ? (string) $data['account'] : null;

        return new ParsedNotify(
            provider: self::PROVIDER,
            event: $verified && $status === '2' ? 'TRANSACTION.SUCCESS' : 'TRANSACTION.UNKNOWN',
            outTradeNo: (string) ($data['third_order_id'] ?? ''),
            tradeNo: $data['leshua_order_id'] ?? null,
            status: $status,
            amount: $amount,
            data: array_merge($data, ['signature_verified' => $verified]),
            raw: [
                'body' => $body,
                'headers' => $headers,
            ]
        );
    }

    private function requestForm(string $operation, array $params): GatewayResponse
    {
        $payload = http_build_query(Payload::filter($params));
        $request = [
            'method' => 'POST',
            'uri' => $this->config()->get('gateway_uri', self::GATEWAY_URI),
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'payload' => $payload,
            'params' => $params,
        ];

        try {
            $response = $this->httpClient->request(
                'POST',
                $request['uri'],
                $payload,
                $request['headers']
            );
        } catch (\Throwable $exception) {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: $operation,
                request: $request,
                message: $exception->getMessage()
            );
        }

        $body = is_string($response['body'] ?? null) ? $response['body'] : '';
        $parsed = $body !== '' ? Xml::decode($body) : [];

        return GatewayResponse::success(
            provider: self::PROVIDER,
            operation: $operation,
            request: $request,
            data: [
                'response' => $response,
                'parsed' => $parsed,
            ]
        );
    }

    private function merchantId(): string
    {
        return $this->config()->requireString('merchant_id');
    }

    private function tradeKey(): string
    {
        return $this->config()->requireString('trade_key');
    }

    private function notifyKey(): string
    {
        return $this->config()->requireString('notify_key');
    }

    private function defaultPayWay(string $channel): string
    {
        return match ($channel) {
            'alipay' => 'ZFBZF',
            'wxpay', 'wechat', 'wechatpay' => 'WXZF',
            'bank', 'unionpay' => 'UPSMZF',
            default => throw new \InvalidArgumentException(sprintf('Unsupported Leshua channel "%s".', $channel)),
        };
    }

    private function defaultJspayFlag(string $channel, string $scene): string
    {
        $scene = strtolower($scene);

        return match ($channel) {
            'alipay' => $scene === 'jsapi' ? '1' : '0',
            'wxpay', 'wechat', 'wechatpay' => match ($scene) {
                'jsapi' => '1',
                'mini' => '3',
                default => '2',
            },
            'bank', 'unionpay' => '0',
            default => throw new \InvalidArgumentException(sprintf('Unsupported Leshua channel "%s".', $channel)),
        };
    }

    private function makeSign(array $params, string $key): string
    {
        ksort($params);
        $pairs = [];

        foreach ($params as $name => $value) {
            if (in_array($name, ['sign', 'error_code'], true)) {
                continue;
            }

            if (is_array($value)) {
                $value = '';
            }

            $pairs[] = sprintf('%s=%s', $name, $value);
        }

        return strtoupper(md5(implode('&', $pairs) . '&key=' . $key));
    }
}
