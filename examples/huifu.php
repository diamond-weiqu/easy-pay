<?php

declare(strict_types=1);

use EasyPay\Payment\Core\PaymentManager;
use EasyPay\Payment\Request\PayOrder;
use EasyPay\Payment\Request\QueryOrder;
use EasyPay\Payment\Support\CallableHttpClient;
use EasyPay\Payment\Support\RsaSha256;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!extension_loaded('openssl')) {
    throw new RuntimeException('The openssl extension is required for this example.');
}

function generateRsaKeyPair(): array
{
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    if ($resource === false) {
        throw new RuntimeException('Unable to generate RSA key pair.');
    }

    $privateKey = '';
    if (!openssl_pkey_export($resource, $privateKey)) {
        throw new RuntimeException('Unable to export private key.');
    }

    $details = openssl_pkey_get_details($resource);
    if (!is_array($details) || !isset($details['key'])) {
        throw new RuntimeException('Unable to export public key.');
    }

    return [
        'private' => $privateKey,
        'public' => $details['key'],
    ];
}

function huifuSignContent(array $params): string
{
    $params = array_filter($params, static fn ($value): bool => $value !== null);
    ksort($params);

    return (string) json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

$merchantKeys = generateRsaKeyPair();
$platformKeys = generateRsaKeyPair();

$client = new CallableHttpClient(
    static function (string $method, string $uri, array|string|null $payload, array $headers) use ($platformKeys): array {
        $body = is_string($payload) ? $payload : (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $envelope = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $requestData = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '');

        $responseData = match (true) {
            str_ends_with($path, '/v3/trade/payment/jspay') => [
                'resp_code' => '00000000',
                'resp_desc' => 'success',
                'req_seq_id' => (string) ($requestData['req_seq_id'] ?? ''),
                'hf_seq_id' => 'HF202603150001',
                'trans_stat' => 'S',
                'trans_amt' => (string) ($requestData['trans_amt'] ?? ''),
                'qr_code' => 'https://pay.example.test/qrcode/HF202603150001',
            ],
            str_ends_with($path, '/v3/trade/payment/scanpay/query') => [
                'resp_code' => '00000000',
                'resp_desc' => 'success',
                'req_seq_id' => (string) ($requestData['org_req_seq_id'] ?? '202603150001'),
                'hf_seq_id' => (string) ($requestData['org_hf_seq_id'] ?? 'HF202603150001'),
                'trans_stat' => 'S',
                'trans_amt' => '99.00',
            ],
            default => [
                'resp_code' => '00000000',
                'resp_desc' => 'success',
            ],
        };

        $responseEnvelope = [
            'data' => $responseData,
            'sign' => RsaSha256::sign(huifuSignContent($responseData), $platformKeys['private']),
        ];

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($responseEnvelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'method' => $method,
            'uri' => $uri,
            'request_headers' => $headers,
        ];
    }
);

$gateway = PaymentManager::make('huifu', [
    'sys_id' => '6666000108854952',
    'product_id' => 'test-product',
    'merchant_private_key' => $merchantKeys['private'],
    'huifu_public_key' => $platformKeys['public'],
    'huifu_id' => '6666000108854952',
], $client);

$create = $gateway->create(new PayOrder(
    outTradeNo: '202603150001',
    amount: '99.00',
    subject: 'Huifu Direct Order',
    notifyUrl: 'https://demo.test/notify/huifu',
    returnUrl: 'https://demo.test/return/huifu',
    scene: 'native',
    clientIp: '127.0.0.1',
    metadata: [
        'channel' => 'alipay',
    ]
));

echo "== create ==\n";
print_r($create->toArray());

$tradeNo = (string) ($create->data['result']['hf_seq_id'] ?? '');
if ($tradeNo !== '') {
    $query = $gateway->query(new QueryOrder(tradeNo: $tradeNo));
    echo "\n== query ==\n";
    print_r($query->toArray());
}

$notifyData = [
    'req_seq_id' => '202603150001',
    'hf_seq_id' => 'HF202603150001',
    'trans_stat' => 'S',
    'trans_amt' => '99.00',
    'resp_code' => '00000000',
];
$respData = json_encode($notifyData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$notifyBody = http_build_query([
    'resp_data' => $respData,
    'sign' => RsaSha256::sign($respData, $platformKeys['private']),
]);

$notify = $gateway->parseNotify($notifyBody);
echo "\n== notify ==\n";
print_r($notify->toArray());
