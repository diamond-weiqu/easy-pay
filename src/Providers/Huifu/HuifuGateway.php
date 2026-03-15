<?php

declare(strict_types=1);

namespace EasyPay\Payment\Providers\Huifu;

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
use EasyPay\Payment\Support\KeyValue;
use EasyPay\Payment\Support\Payload;
use EasyPay\Payment\Support\RsaSha256;

final class HuifuGateway extends AbstractGateway implements GatewayInterface
{
    private const PROVIDER = 'huifu';
    private const GATEWAY_URI = 'https://api.huifu.com';

    public function create(PayOrder $order): GatewayResponse
    {
        $mode = strtolower((string) ($order->metadata['mode'] ?? 'direct'));

        if ($mode === 'hosting') {
            return $this->createHostingOrder($order);
        }

        return $this->createDirectOrder($order);
    }

    public function query(QueryOrder $query): GatewayResponse
    {
        $data = [
            'req_seq_id' => $this->newRequestId('Q'),
            'req_date' => date('Ymd'),
            'huifu_id' => $this->huifuId(),
        ];

        if ($query->tradeNo !== null && trim($query->tradeNo) !== '') {
            $data['org_hf_seq_id'] = $query->tradeNo;
        } else {
            $orgReqDate = $this->inferTradeDate($query->outTradeNo);
            if ($query->outTradeNo === null || $orgReqDate === null) {
                return GatewayResponse::failure(
                    provider: self::PROVIDER,
                    operation: 'query',
                    request: ['out_trade_no' => $query->outTradeNo, 'trade_no' => $query->tradeNo],
                    message: 'Huifu query requires tradeNo, or outTradeNo prefixed with YYYYMMDD.'
                );
            }

            $data['org_req_seq_id'] = $query->outTradeNo;
            $data['org_req_date'] = $orgReqDate;
        }

        return $this->requestApi('query', '/v3/trade/payment/scanpay/query', $data);
    }

    public function close(CloseOrder $order): GatewayResponse
    {
        $orgReqDate = $this->inferTradeDate($order->outTradeNo);
        if ($orgReqDate === null) {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: 'close',
                request: ['out_trade_no' => $order->outTradeNo],
                message: 'Huifu close requires outTradeNo prefixed with YYYYMMDD.'
            );
        }

        $data = [
            'req_date' => date('Ymd'),
            'req_seq_id' => $this->newRequestId('C'),
            'huifu_id' => $this->huifuId(),
            'org_req_date' => $orgReqDate,
            'org_req_seq_id' => $order->outTradeNo,
        ];

        return $this->requestApi('close', '/v2/trade/payment/scanpay/close', $data);
    }

    public function refund(RefundOrder $order): GatewayResponse
    {
        $mode = strtolower((string) ($order->metadata['mode'] ?? 'direct'));
        if ($mode === 'hosting') {
            return $this->refundHostingOrder($order);
        }

        $data = [
            'req_date' => date('Ymd'),
            'req_seq_id' => $order->refundNo,
            'huifu_id' => $this->huifuId(),
            'refund_amt' => $order->amount,
        ];

        $orgHfSeqId = $order->metadata['org_hf_seq_id'] ?? $order->metadata['trade_no'] ?? null;
        if (is_string($orgHfSeqId) && trim($orgHfSeqId) !== '') {
            $data['org_hf_seq_id'] = $orgHfSeqId;
        } else {
            $orgReqDate = $order->metadata['org_req_date'] ?? $this->inferTradeDate($order->outTradeNo);
            if (!is_string($orgReqDate) || trim($orgReqDate) === '') {
                return GatewayResponse::failure(
                    provider: self::PROVIDER,
                    operation: 'refund',
                    request: $data,
                    message: 'Huifu refund requires metadata.org_hf_seq_id, or metadata.org_req_date with outTradeNo.'
                );
            }

            $data['org_req_seq_id'] = $order->outTradeNo;
            $data['org_req_date'] = $orgReqDate;
        }

        if (isset($order->metadata['acct_split_bunch'])) {
            $data['acct_split_bunch'] = $this->jsonField($order->metadata['acct_split_bunch']);
        }

        return $this->requestApi('refund', '/v3/trade/payment/scanpay/refund', $data);
    }

    public function refundQuery(RefundQuery $query): GatewayResponse
    {
        $orgReqDate = $this->inferTradeDate($query->refundNo);
        if ($orgReqDate === null) {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: 'refund_query',
                request: ['refund_no' => $query->refundNo],
                message: 'Huifu refund query requires refundNo prefixed with YYYYMMDD, or use a dated request id.'
            );
        }

        $data = [
            'req_seq_id' => $this->newRequestId('RQ'),
            'req_date' => date('Ymd'),
            'huifu_id' => $this->huifuId(),
            'org_req_seq_id' => $query->refundNo,
            'org_req_date' => $orgReqDate,
        ];

        return $this->requestApi('refund_query', '/v3/trade/payment/scanpay/refundquery', $data);
    }

    public function parseNotify(string $body, array $headers = []): ParsedNotify
    {
        $headers = Header::normalize($headers);
        $payload = $this->decodeNotifyEnvelope($body);
        $respData = $payload['resp_data'] ?? null;
        $sign = $payload['sign'] ?? null;

        $respDataString = is_string($respData)
            ? $respData
            : (is_array($respData) ? $this->jsonEncode($respData) : '');

        $verified = false;
        if ($respDataString !== '' && is_string($sign) && $sign !== '') {
            $verified = RsaSha256::verify(
                $respDataString,
                $sign,
                $this->platformPublicKey()
            );
        }

        $data = $respDataString !== '' ? json_decode($respDataString, true) : [];
        $data = is_array($data) ? $data : [];
        $status = (string) ($data['trans_stat'] ?? ($data['resp_code'] ?? ''));

        return new ParsedNotify(
            provider: self::PROVIDER,
            event: $verified && $status === 'S' ? 'TRANSACTION.SUCCESS' : 'TRANSACTION.NOTIFY',
            outTradeNo: (string) ($data['req_seq_id'] ?? ''),
            tradeNo: $data['hf_seq_id'] ?? null,
            status: $status,
            amount: isset($data['trans_amt']) ? (string) $data['trans_amt'] : (isset($data['ord_amt']) ? (string) $data['ord_amt'] : null),
            data: array_merge($data, ['signature_verified' => $verified]),
            raw: [
                'body' => $body,
                'headers' => $headers,
                'payload' => $payload,
            ]
        );
    }

    private function createDirectOrder(PayOrder $order): GatewayResponse
    {
        $channel = $this->resolveChannel($order);
        if ($channel === null) {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: 'create',
                request: ['metadata' => $order->metadata],
                message: 'Huifu create requires metadata.channel: alipay, wxpay, bank, or ecny.'
            );
        }

        $tradeType = $this->resolveTradeType($channel, $order->scene);
        if ($tradeType === null) {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: 'create',
                request: ['channel' => $channel, 'scene' => $order->scene],
                message: 'Unsupported Huifu trade type combination.'
            );
        }

        $data = [
            'req_date' => $this->requestDate($order->outTradeNo),
            'req_seq_id' => $order->outTradeNo,
            'huifu_id' => $this->huifuId(),
            'trade_type' => $tradeType,
            'trans_amt' => $order->amount,
            'goods_desc' => $order->descriptionText(),
            'notify_url' => $order->notifyUrl ?: $this->config()->get('notify_url'),
        ];

        $clientIp = $order->clientIp ?: (string) $this->config()->get('client_ip', '127.0.0.1');
        if ($clientIp !== '') {
            $data['risk_check_data'] = $this->jsonEncode(['ip_addr' => $clientIp]);
        }

        if (str_starts_with($tradeType, 'T_')) {
            $data['wx_data'] = $this->buildWechatData($tradeType, $order, $clientIp);
        }

        if (str_starts_with($tradeType, 'A_')) {
            $data['alipay_data'] = $this->buildAlipayData($tradeType, $order);
        }

        if (str_starts_with($tradeType, 'U_')) {
            $data['unionpay_data'] = $this->buildUnionpayData($tradeType, $order, $clientIp);
        }

        if (isset($order->metadata['acct_split_bunch'])) {
            $data['acct_split_bunch'] = $this->jsonField($order->metadata['acct_split_bunch']);
        }

        return $this->requestApi('create', '/v3/trade/payment/jspay', $data);
    }

    private function createHostingOrder(PayOrder $order): GatewayResponse
    {
        $channel = $this->resolveChannel($order);
        if ($channel === null) {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: 'create',
                request: ['metadata' => $order->metadata],
                message: 'Huifu hosting create requires metadata.channel.'
            );
        }

        $data = [
            'req_date' => $this->requestDate($order->outTradeNo),
            'req_seq_id' => $order->outTradeNo,
            'huifu_id' => $this->huifuId(),
            'trans_amt' => $order->amount,
            'goods_desc' => $order->descriptionText(),
            'notify_url' => $order->notifyUrl ?: $this->config()->get('notify_url'),
        ];

        $scene = strtolower($order->scene);
        if (in_array($scene, ['web', 'page', 'pc', 'h5', 'wap'], true)) {
            $requestType = in_array($scene, ['h5', 'wap'], true) ? 'M' : 'P';
            $projectId = $order->metadata['project_id'] ?? $this->config()->get('project_id');
            if (!is_string($projectId) || trim($projectId) === '') {
                return GatewayResponse::failure(
                    provider: self::PROVIDER,
                    operation: 'create',
                    request: ['scene' => $scene],
                    message: 'Huifu hosting H5/PC payment requires project_id.'
                );
            }

            $data['pre_order_type'] = '1';
            $data['trans_type'] = $channel === 'alipay' ? 'A_JSAPI' : 'T_JSAPI';
            $data['hosting_data'] = $this->jsonEncode([
                'project_title' => $order->metadata['project_title'] ?? $this->config()->get('project_title', 'easy-pay'),
                'project_id' => $projectId,
                'callback_url' => $order->returnUrl ?: $this->config()->get('return_url'),
                'request_type' => $order->metadata['request_type'] ?? $requestType,
            ]);
        } elseif ($channel === 'alipay' && $scene === 'app') {
            $data['pre_order_type'] = '2';
            $data['app_data'] = $this->jsonEncode([
                'app_schema' => $order->metadata['app_schema'] ?? ($order->returnUrl ?: $this->config()->get('return_url', '')),
            ]);
        } elseif ($channel === 'wxpay' && in_array($scene, ['mini', 'miniapp', 'app'], true)) {
            $miniappData = [
                'need_scheme' => $order->metadata['need_scheme'] ?? 'Y',
            ];
            $seqId = $order->metadata['seq_id'] ?? $this->config()->get('seq_id');
            if (is_string($seqId) && trim($seqId) !== '') {
                $miniappData['seq_id'] = $seqId;
            }

            $data['pre_order_type'] = '3';
            $data['miniapp_data'] = $this->jsonEncode($miniappData);
        } else {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: 'create',
                request: ['channel' => $channel, 'scene' => $scene],
                message: 'Unsupported Huifu hosting payment scene.'
            );
        }

        if (isset($order->metadata['acct_split_bunch'])) {
            $data['acct_split_bunch'] = $this->jsonField($order->metadata['acct_split_bunch']);
        }

        return $this->requestApi('create', '/v2/trade/hosting/payment/preorder', $data);
    }

    private function refundHostingOrder(RefundOrder $order): GatewayResponse
    {
        $orgReqDate = $order->metadata['org_req_date'] ?? $this->inferTradeDate($order->outTradeNo);
        if (!is_string($orgReqDate) || trim($orgReqDate) === '') {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: 'refund',
                request: ['out_trade_no' => $order->outTradeNo],
                message: 'Huifu hosting refund requires metadata.org_req_date, or outTradeNo prefixed with YYYYMMDD.'
            );
        }

        $data = [
            'req_date' => date('Ymd'),
            'req_seq_id' => $order->refundNo,
            'huifu_id' => $this->huifuId(),
            'ord_amt' => $order->amount,
            'org_req_date' => $orgReqDate,
            'org_req_seq_id' => $order->outTradeNo,
        ];

        return $this->requestApi('refund', '/v2/trade/hosting/payment/htRefund', $data);
    }

    private function requestApi(string $operation, string $path, array $data): GatewayResponse
    {
        $envelope = [
            'sys_id' => $this->sysId(),
            'product_id' => $this->productId(),
            'data' => $data,
        ];
        $envelope['sign'] = $this->makeSign($data);

        $body = $this->jsonEncode($envelope);
        $uri = rtrim((string) $this->config()->get('gateway_uri', self::GATEWAY_URI), '/') . $path;
        $headers = ['Content-Type' => 'application/json; charset=utf-8'];
        $request = [
            'method' => 'POST',
            'uri' => $uri,
            'headers' => $headers,
            'payload' => $body,
            'data' => $data,
        ];

        try {
            $response = $this->httpClient->request('POST', $uri, $body, $headers);
        } catch (\Throwable $exception) {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: $operation,
                request: $request,
                message: $exception->getMessage()
            );
        }

        $parsed = $this->decodeApiResponse($response);
        if (!isset($parsed['data'], $parsed['sign']) || !is_array($parsed['data'])) {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: $operation,
                request: $request,
                message: 'Huifu response format invalid.',
                data: ['response' => $response, 'parsed' => $parsed]
            );
        }

        if (!$this->checkResponseSign($parsed['data'], (string) $parsed['sign'])) {
            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: $operation,
                request: $request,
                message: 'Huifu response signature verification failed.',
                data: ['response' => $response, 'parsed' => $parsed]
            );
        }

        $result = $parsed['data'];
        if (isset($result['resp_code']) && !in_array((string) $result['resp_code'], ['00000000', '00000100'], true)) {
            $message = (string) ($result['resp_desc'] ?? 'Huifu request failed.');
            if (isset($result['bank_message']) && is_string($result['bank_message']) && $result['bank_message'] !== '') {
                $message .= ' ' . $result['bank_message'];
            }

            return GatewayResponse::failure(
                provider: self::PROVIDER,
                operation: $operation,
                request: $request,
                message: $message,
                data: ['response' => $response, 'parsed' => $parsed, 'result' => $result]
            );
        }

        return GatewayResponse::success(
            provider: self::PROVIDER,
            operation: $operation,
            request: $request,
            data: [
                'response' => $response,
                'parsed' => $parsed,
                'result' => $result,
                'pay_info' => $this->normalizePayInfo($result),
            ]
        );
    }

    private function normalizePayInfo(array $result): mixed
    {
        if (isset($result['pay_info']) && is_string($result['pay_info'])) {
            $decoded = json_decode($result['pay_info'], true);
            return is_array($decoded) ? $decoded : $result['pay_info'];
        }

        if (isset($result['miniapp_data']) && is_string($result['miniapp_data'])) {
            $decoded = json_decode($result['miniapp_data'], true);
            return is_array($decoded) ? $decoded : $result['miniapp_data'];
        }

        if (isset($result['qr_code'])) {
            return $result['qr_code'];
        }

        if (isset($result['jump_url'])) {
            return $result['jump_url'];
        }

        return null;
    }

    private function decodeApiResponse(array $response): array
    {
        $body = $response['body'] ?? null;
        if (is_string($body) && trim($body) !== '') {
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_array($body)) {
            return $body;
        }

        if (isset($response['data'], $response['sign'])) {
            return $response;
        }

        return [];
    }

    private function decodeNotifyEnvelope(string $body): array
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return [];
        }

        if ($trimmed[0] === '{') {
            $decoded = json_decode($trimmed, true);
            return is_array($decoded) ? $decoded : [];
        }

        parse_str($trimmed, $data);
        return is_array($data) ? $data : [];
    }

    private function buildWechatData(string $tradeType, PayOrder $order, string $clientIp): string
    {
        $data = ['spbill_create_ip' => $clientIp];

        if (in_array($tradeType, ['T_JSAPI', 'T_MINIAPP'], true)) {
            $subAppId = $order->metadata['sub_appid'] ?? $this->config()->get('sub_appid');
            $openid = $order->openid ?? ($order->metadata['sub_openid'] ?? null);

            if (!is_string($subAppId) || trim($subAppId) === '' || !is_string($openid) || trim($openid) === '') {
                throw new \InvalidArgumentException('Huifu wechat JSAPI/MINIAPP requires sub_appid and openid.');
            }

            $data['sub_appid'] = $subAppId;
            $data['sub_openid'] = $openid;
            $data['device_info'] = '4';
        }

        if ($tradeType === 'T_NATIVE') {
            $data['product_id'] = $order->metadata['product_id'] ?? '01001';
        }

        return $this->jsonEncode($data);
    }

    private function buildAlipayData(string $tradeType, PayOrder $order): string
    {
        $data = ['subject' => $order->descriptionText()];

        if ($tradeType === 'A_JSAPI') {
            $buyerId = $order->openid ?? ($order->metadata['buyer_id'] ?? null);
            if (!is_string($buyerId) || trim($buyerId) === '') {
                throw new \InvalidArgumentException('Huifu alipay JSAPI requires order openid or metadata.buyer_id.');
            }
            $data['buyer_id'] = $buyerId;
        }

        return $this->jsonEncode($data);
    }

    private function buildUnionpayData(string $tradeType, PayOrder $order, string $clientIp): string
    {
        $data = ['customer_ip' => $clientIp];

        if ($tradeType === 'U_JSAPI') {
            $userId = $order->openid ?? ($order->metadata['user_id'] ?? null);
            if (!is_string($userId) || trim($userId) === '') {
                throw new \InvalidArgumentException('Huifu unionpay JSAPI requires order openid or metadata.user_id.');
            }
            $data['user_id'] = $userId;
            $data['qr_code'] = $order->returnUrl ?: (string) ($order->metadata['qr_code'] ?? $this->config()->get('qr_code', ''));
        }

        return $this->jsonEncode($data);
    }

    private function resolveChannel(PayOrder $order): ?string
    {
        $channel = strtolower((string) ($order->metadata['channel'] ?? $order->metadata['payment_channel'] ?? ''));
        return $channel === '' ? null : $channel;
    }

    private function resolveTradeType(string $channel, string $scene): ?string
    {
        $scene = strtolower($scene);

        return match ($channel) {
            'alipay' => $scene === 'jsapi' ? 'A_JSAPI' : 'A_NATIVE',
            'wxpay', 'wechat', 'wechatpay' => match ($scene) {
                'jsapi' => 'T_JSAPI',
                'mini', 'miniapp' => 'T_MINIAPP',
                default => 'T_NATIVE',
            },
            'bank', 'unionpay' => $scene === 'jsapi' ? 'U_JSAPI' : 'U_NATIVE',
            'ecny' => 'D_NATIVE',
            default => null,
        };
    }

    private function makeSign(array $params): string
    {
        return RsaSha256::sign($this->signContent($params), $this->merchantPrivateKey());
    }

    private function checkResponseSign(array $params, string $sign): bool
    {
        return RsaSha256::verify($this->signContent($params), $sign, $this->platformPublicKey());
    }

    private function signContent(array $params): string
    {
        $params = array_filter($params, static fn ($value): bool => $value !== null);
        ksort($params);

        return $this->jsonEncode($params);
    }

    private function requestDate(string $tradeNo): string
    {
        return $this->inferTradeDate($tradeNo) ?? date('Ymd');
    }

    private function inferTradeDate(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        if (preg_match('/^(\d{8})/', $value, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function newRequestId(string $prefix): string
    {
        return $prefix . date('YmdHis') . random_int(100000, 999999);
    }

    private function jsonField(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return $this->jsonEncode($value);
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function sysId(): string
    {
        return $this->config()->requireString('sys_id');
    }

    private function productId(): string
    {
        return $this->config()->requireString('product_id');
    }

    private function huifuId(): string
    {
        $huifuId = $this->config()->get('huifu_id')
            ?? $this->config()->get('sub_huifu_id')
            ?? $this->config()->get('merchant_id');

        if (is_string($huifuId) && trim($huifuId) !== '') {
            return $huifuId;
        }

        return $this->sysId();
    }

    private function merchantPrivateKey(): string
    {
        return KeyValue::require(
            $this->config()->get('merchant_private_key') ?? $this->config()->get('private_key'),
            'merchant_private_key'
        );
    }

    private function platformPublicKey(): string
    {
        return KeyValue::require(
            $this->config()->get('huifu_public_key')
                ?? $this->config()->get('platform_public_key')
                ?? $this->config()->get('public_key'),
            'huifu_public_key'
        );
    }
}
