<?php

declare(strict_types=1);

use EasyPay\Payment\Core\PaymentManager;
use EasyPay\Payment\Request\PayOrder;
use EasyPay\Payment\Support\CallableHttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = new CallableHttpClient(
    static function (string $method, string $uri, array|string|null $payload, array $headers): array {
        return [
            'mock' => true,
            'method' => $method,
            'uri' => $uri,
            'payload' => $payload,
            'headers' => $headers,
        ];
    }
);

$alipay = PaymentManager::make('alipay', [
    'app_id' => '2026000000000000',
], $client);

$response = $alipay->create(new PayOrder(
    outTradeNo: 'T202603140001',
    amount: '99.00',
    subject: 'VIP Subscription',
    notifyUrl: 'https://demo.test/notify/alipay',
    returnUrl: 'https://demo.test/return/alipay',
    scene: 'web'
));

print_r($response->toArray());

