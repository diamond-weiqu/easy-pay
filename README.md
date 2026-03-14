# easy-pay

[![Github](https://img.shields.io/github/followers/iGoogle-ink?label=Follow&style=social)](https://github.com/iGoogle-ink)
[![Github](https://img.shields.io/github/forks/diamond-weiqu/easy-pay?label=Fork&style=social)](https://github.com/diamond-weiqu/easy-pay/fork)

[![Php](https://img.shields.io/badge/php->=8.0.0-brightgreen.svg)](https://golang.google.cn)
[![Go](https://github.com/go-pay/gopay/actions/workflows/go.yml/badge.svg)](https://github.com/go-pay/gopay/actions/workflows/go.yml)
[![GitHub Release](https://img.shields.io/github/v/release/diamond-weiqu/easy-pay)](https://github.com/go-pay/gopay/releases)
[![License](https://img.shields.io/github/license/diamond-weiqu/easy-pay)](https://www.apache.org/licenses/LICENSE-2.0)
[![Go Report Card](https://goreportcard.com/badge/github.com/go-pay/gopay)](https://goreportcard.com/report/github.com/diamond-weiqu/easy-pay)

`easy-pay` 是一个面向 PHP 生态的轻量支付 SDK，提供统一接口来接入官方支付和第三方聚合支付渠道。

当前版本已内置支付宝、微信支付、乐刷聚合支付、拉卡拉支付的基础接入能力，适合作为业务系统或开源项目的支付基础层。

## 安装

```bash
composer require moxianbao/easy-pay
```

## 当前已对接渠道

- 支付宝 `alipay`
- 微信支付 `wechatpay`
- 乐刷聚合支付 `leshua`
- 拉卡拉支付 `lakala`

## 快速开始

### 1. 创建网关

```php
<?php

use EasyPay\Payment\Core\PaymentManager;
use EasyPay\Payment\Support\CallableHttpClient;

$client = new CallableHttpClient(
    static function (string $method, string $uri, array|string|null $payload, array $headers): array {
        return [
            'status' => 200,
            'method' => $method,
            'uri' => $uri,
            'headers' => $headers,
            'payload' => $payload,
            'body' => '{}',
        ];
    }
);

$gateway = PaymentManager::make('alipay', [
    'app_id' => '2026000000000000',
    'private_key' => '/path/to/alipay-private.pem',
    'public_key' => '/path/to/alipay-public.pem',
], $client);
```

### 2. 发起支付

```php
<?php

use EasyPay\Payment\Request\PayOrder;

$response = $gateway->create(new PayOrder(
    outTradeNo: 'T202603140001',
    amount: '99.00',
    subject: 'VIP Subscription',
    notifyUrl: 'https://demo.test/notify/alipay',
    returnUrl: 'https://demo.test/return/alipay',
    scene: 'web'
));

print_r($response->toArray());
```

### 3. 处理回调

```php
<?php

$notify = $gateway->parseNotify($rawBody, getallheaders());

if (in_array($notify->status, ['TRADE_SUCCESS', 'SUCCESS', '2'], true)) {
    // 这里更新你的业务订单状态
}
```

## 如何传入密钥信息

所有密钥、证书配置都支持两种传法：

- 直接传字符串内容，例如 PEM 文本、证书内容、平台公钥、交易密钥
- 直接传本地文件路径，`easy-pay` 会自动读取文件内容

例如这两种写法都可以：

```php
[
    'private_key' => file_get_contents(__DIR__ . '/cert/private.pem'),
    'public_key' => file_get_contents(__DIR__ . '/cert/public.pem'),
]
```

```php
[
    'private_key' => __DIR__ . '/cert/private.pem',
    'public_key' => __DIR__ . '/cert/public.pem',
]
```

## 配置说明

### 支付宝 `alipay`

公钥模式配置：

```php
[
    'app_id' => '2026000000000000',
    'private_key' => '/path/to/app-private.pem',
    'public_key' => '/path/to/alipay-public.pem',
    'sign_type' => 'RSA2',
    'gateway_uri' => 'https://openapi.alipay.com/gateway.do',
]
```

证书模式配置：

```php
[
    'app_id' => '2026000000000000',
    'private_key' => '/path/to/app-private.pem',
    'app_cert' => '/path/to/appCertPublicKey.crt',
    'alipay_public_cert' => '/path/to/alipayCertPublicKey_RSA2.crt',
    'alipay_root_cert' => '/path/to/alipayRootCert.crt',
    'sign_type' => 'RSA2',
    'gateway_uri' => 'https://openapi.alipay.com/gateway.do',
]
```

支付宝证书模式说明：

- `private_key`：应用私钥内容或路径，用于请求签名
- `app_cert`：应用公钥证书内容或路径，用于自动计算 `app_cert_sn`
- `alipay_public_cert`：支付宝公钥证书内容或路径，用于异步通知验签
- `alipay_root_cert`：支付宝根证书内容或路径，用于自动计算 `alipay_root_cert_sn`
- 如果你已经自行算好了证书序列号，也可以直接传 `app_cert_sn` 和 `alipay_root_cert_sn`

### 微信支付 `wechatpay`

```php
[
    'app_id' => 'wx1234567890',
    'mch_id' => '1900000109',
    'serial_no' => '商户证书序列号',
    'private_key' => '/path/to/apiclient_key.pem',
    'api_v3_key' => '32位APIv3Key',
    'platform_public_key' => '/path/to/wechatpay-platform.pem',
    'gateway_uri' => 'https://api.mch.weixin.qq.com/v3',
]
```

如果你需要按平台证书序列号切换微信支付平台公钥：

```php
[
    'platform_public_keys' => [
        'SERIAL_1' => '/path/to/platform-1.pem',
        'SERIAL_2' => '/path/to/platform-2.pem',
    ],
]
```

### 乐刷聚合支付 `leshua`

```php
[
    'merchant_id' => '你的乐刷商户号',
    'trade_key' => '交易密钥',
    'notify_key' => '异步通知密钥',
    'gateway_uri' => 'https://paygate.leshuazf.com/cgi-bin/lepos_pay_gateway.cgi',
]
```

乐刷下单时需要在 `PayOrder::metadata` 里传入支付渠道：

- `channel`: `alipay` / `wxpay` / `bank`
- `sub_appid`: 微信公众号或小程序 `appid`，仅 JSAPI / 小程序场景需要
- `scene`: 推荐使用 `native`、`jsapi`、`mini`

乐刷示例：

```php
$gateway = PaymentManager::make('leshua', [
    'merchant_id' => '10000001',
    'trade_key' => 'trade-key',
    'notify_key' => 'notify-key',
], $client);

$response = $gateway->create(new PayOrder(
    outTradeNo: 'LS202603140001',
    amount: '88.00',
    subject: 'Leshua Order',
    notifyUrl: 'https://demo.test/notify/leshua',
    scene: 'native',
    metadata: [
        'channel' => 'alipay',
    ]
));
```

如果你发起乐刷退款，需要在 `RefundOrder::metadata` 中补充上游交易号：

```php
[
    'trade_no' => '乐刷返回的 leshua_order_id',
]
```

### 拉卡拉支付 `lakala`

```php
[
    'app_id' => '拉卡拉APPID',
    'merchant_no' => '商户号',
    'term_no' => '终端号',
    'merchant_cert' => '/path/to/api_cert.cer',
    'merchant_private_key' => '/path/to/api_private_key.pem',
    'platform_cert' => '/path/to/lkl-apigw-v1.cer',
    'sandbox' => false,
    'gateway_uri' => 'https://s2.lakala.com',
]
```

拉卡拉证书传参说明：

- `merchant_cert`：商户证书内容或路径，用于读取证书序列号
- `merchant_private_key`：商户私钥内容或路径，用于请求签名
- `platform_cert`：拉卡拉平台证书内容或路径，用于回调验签
- `merchant_serial_no`：可选；如果你不想从商户证书里自动读取序列号，可以直接传入

拉卡拉下单时需要在 `PayOrder::metadata` 中指定支付渠道：

- `channel`: `alipay` / `wxpay` / `bank`
- `trans_type`: 可选，不传时会按 `scene` 自动推导，`native => 41`、`jsapi => 51`、`mini => 71`
- `sub_appid`: 微信公众号或小程序 `appid`
- `extend`: 可选，会映射到 `acc_busi_fields`

拉卡拉示例：

```php
$gateway = PaymentManager::make('lakala', [
    'app_id' => 'lakala-appid',
    'merchant_no' => 'merchant-no',
    'term_no' => 'term-no',
    'merchant_cert' => __DIR__ . '/cert/api_cert.cer',
    'merchant_private_key' => __DIR__ . '/cert/api_private_key.pem',
    'platform_cert' => __DIR__ . '/cert/lkl-apigw-v1.cer',
], $client);

$response = $gateway->create(new PayOrder(
    outTradeNo: 'LKL202603140001',
    amount: '128.00',
    subject: 'Lakala Order',
    notifyUrl: 'https://demo.test/notify/lakala',
    scene: 'native',
    clientIp: '127.0.0.1',
    metadata: [
        'channel' => 'alipay',
    ]
));
```

如果你发起拉卡拉退款，推荐在 `RefundOrder::metadata` 中补充原交易号：

```php
[
    'trade_no' => '拉卡拉返回的 trade_no',
]
```

## HTTP 客户端

库本身不强耦合任何 HTTP 实现。你可以：

- 直接实现 `EasyPay\Payment\Contracts\HttpClientInterface`
- 开发阶段使用 `EasyPay\Payment\Support\CallableHttpClient`

接口定义：

```php
public function request(
    string $method,
    string $uri,
    array|string|null $payload = null,
    array $headers = []
): array;
```

返回值建议至少包含：

```php
[
    'status' => 200,
    'headers' => [],
    'body' => '...'
]
```

## 示例

查看 [examples/mock-client.php](examples/mock-client.php)

## License

MIT





