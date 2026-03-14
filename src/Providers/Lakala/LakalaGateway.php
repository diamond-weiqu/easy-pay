<?php

declare(strict_types=1);

namespace EasyPay\Payment\Providers\Lakala;

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
use EasyPay\Payment\Support\Header;
use EasyPay\Payment\Support\KeyValue;
use EasyPay\Payment\Support\Payload;

final class LakalaGateway extends AbstractGateway implements GatewayInterface
{
    private const PROVIDER = 'lakala';

    public function create(PayOrder $order): GatewayResponse
    {
        $channel = strtolower((string) ($order->metadata['channel'] ?? $order->metadata['payment_channel'] ?? ''));

        if ($channel === '') {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: 'create',
                request: [],
                message: 'Lakala create requires metadata.channel: alipay, wxpay, or bank.'
            );
        }

        $extend = $this->buildExtendFields($order);
        $params = [
            'merchant_no' => $this->merchantNo(),
            'term_no' => $this->termNo(),
            'out_trade_no' => $order->outTradeNo,
            'account_type' => $order->metadata['account_type'] ?? $this->defaultAccountType($channel),
            'trans_type' => $order->metadata['trans_type'] ?? $this->defaultTransType($order->scene),
            'total_amount' => (string) $order->amountInMinorUnits(),
            'location_info' => [
                'request_ip' => $order->clientIp ?: $this->config()->get('client_ip', '127.0.0.1'),
            ],
            'subject' => $order->descriptionText(),
            'notify_url' => $order->notifyUrl ?: $this->config()->get('notify_url'),
        ];

        if ($extend !== []) {
            $params['acc_busi_fields'] = $extend;
        }

        return $this->requestJson('create', '/api/v3/labs/trans/preorder', $params);
    }

    public function query(QueryOrder $query): GatewayResponse
    {
        $params = [
            'merchant_no' => $this->merchantNo(),
            'term_no' => $this->termNo(),
        ];

        if ($query->outTradeNo !== null && trim($query->outTradeNo) !== '') {
            $params['out_trade_no'] = $query->outTradeNo;
        }

        if ($query->tradeNo !== null && trim($query->tradeNo) !== '') {
            $params['trade_no'] = $query->tradeNo;
        }

        return $this->requestJson('query', '/api/v3/labs/query/tradequery', $params);
    }

    public function close(CloseOrder $order): GatewayResponse
    {
        $params = [
            'merchant_no' => $this->merchantNo(),
            'term_no' => $this->termNo(),
            'out_trade_no' => 'CLOSE' . date('YmdHis') . random_int(1000, 9999),
            'origin_out_trade_no' => $order->outTradeNo,
            'location_info' => [
                'request_ip' => $this->config()->get('client_ip', '127.0.0.1'),
            ],
        ];

        return $this->requestJson('close', '/api/v3/labs/relation/revoked', $params);
    }

    public function refund(RefundOrder $order): GatewayResponse
    {
        $params = [
            'merchant_no' => $this->merchantNo(),
            'term_no' => $this->termNo(),
            'out_trade_no' => $order->refundNo,
            'refund_amount' => (string) $order->amountInMinorUnits(),
            'origin_out_trade_no' => $order->outTradeNo,
            'location_info' => [
                'request_ip' => $order->metadata['client_ip'] ?? $this->config()->get('client_ip', '127.0.0.1'),
            ],
        ];

        $originTradeNo = $order->metadata['trade_no'] ?? $order->metadata['origin_trade_no'] ?? null;
        if (is_string($originTradeNo) && $originTradeNo !== '') {
            $params['origin_trade_no'] = $originTradeNo;
        }

        return $this->requestJson('refund', '/api/v3/labs/relation/refund', $params);
    }

    public function refundQuery(RefundQuery $query): GatewayResponse
    {
        return GatewayResponse::failure(
            provider: self::PROVIDER,
            operation: 'refund_query',
            request: ['refund_no' => $query->refundNo, 'out_trade_no' => $query->outTradeNo],
            message: 'Lakala refund query is not implemented in the current version.'
        );
    }

    public function parseNotify(string $body, array $headers = []): ParsedNotify
    {
        $headers = Header::normalize($headers);
        $payload = json_decode($body, true);
        $payload = is_array($payload) ? $payload : [];

        $authorization = $headers['authorization'] ?? '';
        $verified = $authorization !== '' ? $this->verifyAuthorization($authorization, $body) : false;

        $outTradeNo = (string) ($payload['out_trade_no'] ?? $payload['out_order_no'] ?? '');
        $tradeNo = $payload['trade_no'] ?? ($payload['order_trade_info']['trade_no'] ?? null);
        $status = $payload['trade_status'] ?? ($payload['order_status'] ?? null);
        $buyer = $payload['user_id2'] ?? ($payload['order_trade_info']['user_id2'] ?? null);
        $billTradeNo = $payload['acc_trade_no'] ?? ($payload['order_trade_info']['acc_trade_no'] ?? null);
        $amount = null;

        if (isset($payload['total_amount'])) {
            $amount = number_format(((int) $payload['total_amount']) / 100, 2, '.', '');
        }

        return new ParsedNotify(
            provider: self::PROVIDER,
            event: $verified ? 'TRANSACTION.NOTIFY' : 'TRANSACTION.UNVERIFIED',
            outTradeNo: $outTradeNo,
            tradeNo: is_string($tradeNo) ? $tradeNo : null,
            status: is_string($status) ? $status : null,
            amount: $amount,
            data: array_merge($payload, [
                'buyer' => $buyer,
                'bill_trade_no' => $billTradeNo,
                'signature_verified' => $verified,
            ]),
            raw: [
                'body' => $body,
                'headers' => $headers,
            ]
        );
    }

    private function requestJson(string $operation, string $path, array $reqData): GatewayResponse
    {
        $requestBody = Payload::json([
            'req_time' => date('YmdHis'),
            'version' => str_starts_with($path, '/api/v3/ccss/') ? '1.0' : '3.0',
            'req_data' => $reqData,
        ]);

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json; charset=utf-8',
            'Authorization' => $this->buildAuthorization($requestBody),
        ];

        $request = [
            'method' => 'POST',
            'uri' => $this->gatewayUri() . $path,
            'headers' => $headers,
            'payload' => $requestBody,
            'params' => $reqData,
        ];

        try {
            $response = $this->httpClient->request('POST', $request['uri'], $requestBody, $headers);
        } catch (\Throwable $exception) {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: $operation,
                request: $request,
                message: $exception->getMessage()
            );
        }

        $body = is_string($response['body'] ?? null) ? $response['body'] : '';
        $parsed = $body !== '' ? json_decode($body, true) : null;
        $parsed = is_array($parsed) ? $parsed : [];

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

    private function buildAuthorization(string $body): string
    {
        $appId = $this->config()->requireString('app_id');
        $serialNo = $this->config()->get('merchant_serial_no');

        if (!is_string($serialNo) || trim($serialNo) === '') {
            $serialNo = KeyValue::certificateSerialNumber($this->config()->get('merchant_cert'), 'merchant_cert');
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(6));
        $message = $appId . "\n" . $serialNo . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $signature = $this->sign($message, KeyValue::require($this->config()->get('merchant_private_key'), 'merchant_private_key'));

        return sprintf(
            'LKLAPI-SHA256withRSA appid="%s",serial_no="%s",timestamp="%s",nonce_str="%s",signature="%s"',
            $appId,
            $serialNo,
            $timestamp,
            $nonce,
            $signature
        );
    }

    private function verifyAuthorization(string $authorization, string $body): bool
    {
        $authorization = trim(str_replace('LKLAPI-SHA256withRSA ', '', $authorization));
        $parts = [];

        foreach (explode(',', $authorization) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $segment, 2), 2, '');
            $parts[$name] = trim($value, '"');
        }

        if (!isset($parts['signature'], $parts['timestamp'], $parts['nonce_str'])) {
            return false;
        }

        $message = $parts['timestamp'] . "\n" . $parts['nonce_str'] . "\n" . $body . "\n";
        $certificate = KeyValue::require($this->config()->get('platform_cert'), 'platform_cert');
        $publicKey = openssl_pkey_get_public($certificate);

        if ($publicKey === false) {
            throw new SignatureException('Invalid Lakala platform certificate.');
        }

        return openssl_verify(
            $message,
            base64_decode((string) $parts['signature'], true) ?: '',
            $publicKey,
            OPENSSL_ALGO_SHA256
        ) === 1;
    }

    private function sign(string $message, string $privateKey): string
    {
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw new SignatureException('Invalid Lakala merchant private key.');
        }

        $result = openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256);

        if ($result !== true) {
            throw new SignatureException('Unable to sign Lakala request.');
        }

        return base64_encode($signature);
    }

    private function buildExtendFields(PayOrder $order): array
    {
        $extend = $order->metadata['extend'] ?? [];
        $extend = is_array($extend) ? $extend : [];

        if ($order->openid !== null && $order->openid !== '') {
            $extend['user_id'] = $order->openid;
        }

        $subAppId = $order->metadata['sub_appid'] ?? null;
        if (is_string($subAppId) && $subAppId !== '') {
            $extend['sub_appid'] = $subAppId;
        }

        return $extend;
    }

    private function defaultAccountType(string $channel): string
    {
        return match ($channel) {
            'alipay' => 'ALIPAY',
            'wxpay', 'wechat', 'wechatpay' => 'WECHAT',
            'bank', 'unionpay' => 'UQRCODEPAY',
            default => throw new \InvalidArgumentException(sprintf('Unsupported Lakala channel "%s".', $channel)),
        };
    }

    private function defaultTransType(string $scene): string
    {
        return match (strtolower($scene)) {
            'jsapi' => '51',
            'mini' => '71',
            default => '41',
        };
    }

    private function merchantNo(): string
    {
        return $this->config()->requireString('merchant_no');
    }

    private function termNo(): string
    {
        return $this->config()->requireString('term_no');
    }

    private function gatewayUri(): string
    {
        if ((bool) $this->config()->get('sandbox', false)) {
            return rtrim($this->config()->get('sandbox_gateway_uri', 'https://test.wsmsd.cn/sit'), '/');
        }

        return rtrim($this->config()->get('gateway_uri', 'https://s2.lakala.com'), '/');
    }
}

