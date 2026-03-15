# easy-pay

`easy-pay` 是一个面向 PHP 生态的轻量支付 SDK，提供统一接口来接入官方支付和第三方聚合支付渠道。

当前版本已内置支付宝、微信支付、乐刷聚合支付、拉卡拉支付、斗拱汇付支付的基础接入能力，适合作为业务系统或开源项目的支付基础层。

## 安装

```bash
composer require moxianbao/easy-pay
```

## 当前已对接渠道

- 支付宝 `alipay`
- 微信支付 `wechatpay`
- 乐刷聚合支付 `leshua`
- 拉卡拉支付 `lakala`
- 斗拱汇付支付 `huifu`

斗拱汇付同时支持别名：`dougong`、`dougong_huifu`。

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

$gateway = PaymentManager::make('huifu', [
    'sys_id' => '6666000108854952',
    'product_id' => 'test-product',
    'merchant_private_key' => __DIR__ . '/cert/huifu-private.pem',
    'huifu_public_key' => __DIR__ . '/cert/huifu-public.pem',
    'huifu_id' => '6666000108854952',
], $client);
```

### 2. 发起支付

```php
<?php

use EasyPay\Payment\Request\PayOrder;

$response = $gateway->create(new PayOrder(
    outTradeNo: '202603150001',
    amount: '99.00',
    subject: 'VIP Subscription',
    notifyUrl: 'https://demo.test/notify/huifu',
    returnUrl: 'https://demo.test/return/huifu',
    scene: 'native',
    clientIp: '127.0.0.1',
    metadata: [
        'channel' => 'alipay',
    ]
));

print_r($response->toArray());
```

### 3. 处理回调

```php
<?php

$notify = $gateway->parseNotify($rawBody, getallheaders());

if (in_array($notify->status, ['TRADE_SUCCESS', 'SUCCESS', 'S', '2'], true)) {
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
    'merchant_private_key' => file_get_contents(__DIR__ . '/cert/private.pem'),
    'huifu_public_key' => file_get_contents(__DIR__ . '/cert/public.pem'),
]
```

```php
[
    'merchant_private_key' => __DIR__ . '/cert/private.pem',
    'huifu_public_key' => __DIR__ . '/cert/public.pem',
]
```

各渠道常用密钥字段如下：

- 支付宝：`private_key`、`public_key`，证书模式还支持 `app_cert`、`alipay_public_cert`、`alipay_root_cert`
- 微信支付：`private_key`、`platform_public_key` 或 `platform_public_keys`、`api_v3_key`
- 乐刷：`trade_key`、`notify_key`
- 拉卡拉：`merchant_cert`、`merchant_private_key`、`platform_cert`
- 斗拱汇付：`merchant_private_key`、`huifu_public_key`

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

拉卡拉下单时需要在 `PayOrder::metadata` 中指定支付渠道：

- `channel`: `alipay` / `wxpay` / `bank`
- `trans_type`: 可选，不传时会按 `scene` 自动推导，`native => 41`、`jsapi => 51`、`mini => 71`
- `sub_appid`: 微信公众号或小程序 `appid`
- `extend`: 可选，会映射到 `acc_busi_fields`

如果你发起拉卡拉退款，推荐在 `RefundOrder::metadata` 中补充原交易号：

```php
[
    'trade_no' => '拉卡拉返回的 trade_no',
]
```

### 斗拱汇付支付 `huifu`

```php
[
    'sys_id' => '汇付系统号',
    'product_id' => '汇付产品号',
    'merchant_private_key' => '/path/to/huifu-private.pem',
    'huifu_public_key' => '/path/to/huifu-public.pem',
    'huifu_id' => '汇付子商户号，可选；不填默认使用 sys_id',
    'project_id' => '托管 H5/PC 项目号，可选',
    'seq_id' => '托管小程序应用 ID，可选',
    'notify_url' => 'https://demo.test/notify/huifu',
    'return_url' => 'https://demo.test/return/huifu',
    'gateway_uri' => 'https://api.huifu.com',
]
```

斗拱汇付密钥字段说明：

- `sys_id`：汇付系统号
- `product_id`：汇付产品号
- `merchant_private_key`：商户私钥内容或路径，用于请求签名
- `huifu_public_key`：汇付公钥内容或路径，用于验签响应和异步通知
- `huifu_id`：可选；如果不传，会默认回退到 `sys_id`
- `project_id`：托管 H5/PC 下单时使用
- `seq_id`：托管微信小程序下单时使用

斗拱汇付下单支持两种模式：

- `direct`：默认模式，走 `/v3/trade/payment/jspay`
- `hosting`：托管模式，走 `/v2/trade/hosting/payment/preorder`

`PayOrder::metadata` 常用字段：

- `mode`: `direct` / `hosting`，默认 `direct`
- `channel`: `alipay` / `wxpay` / `bank` / `ecny`
- `sub_appid`: 微信 JSAPI / 小程序场景需要
- `buyer_id`: 支付宝 JSAPI 场景可传买家 `buyer_id`
- `product_id`: 微信 Native 场景可传商品标识，默认 `01001`
- `acct_split_bunch`: 可选，分账信息，支持数组或 JSON 字符串

斗拱汇付 `direct` 模式的场景映射：

- 支付宝：`native`、`jsapi`
- 微信支付：`native`、`jsapi`、`mini`
- 银联：`native`、`jsapi`
- 数字人民币：`native`

斗拱汇付 `hosting` 模式的常用字段：

- H5 / PC：需要 `project_id`，可选 `project_title`、`request_type`
- 支付宝 App：可传 `app_schema`
- 微信小程序 / App：可传 `need_scheme`、`seq_id`

斗拱汇付查询与退款注意事项：

- `query()` 优先使用 `tradeNo` 作为 `org_hf_seq_id`
- 如果没有 `tradeNo`，则 `outTradeNo` 最好以 `YYYYMMDD` 开头，库会自动推导 `org_req_date`
- `close()` 依赖 `outTradeNo` 推导 `org_req_date`，因此同样建议订单号带日期前缀
- `refund()` 推荐在 `RefundOrder::metadata` 里传 `org_hf_seq_id` 或 `trade_no`
- `refundQuery()` 当前依赖 `refundNo` 带 `YYYYMMDD` 前缀，或使用带日期的退款请求号

斗拱汇付直连下单示例：

```php
$gateway = PaymentManager::make('huifu', [
    'sys_id' => '6666000108854952',
    'product_id' => 'test-product',
    'merchant_private_key' => __DIR__ . '/cert/huifu-private.pem',
    'huifu_public_key' => __DIR__ . '/cert/huifu-public.pem',
], $client);

$response = $gateway->create(new PayOrder(
    outTradeNo: '202603150001',
    amount: '88.00',
    subject: 'Huifu Order',
    notifyUrl: 'https://demo.test/notify/huifu',
    scene: 'native',
    clientIp: '127.0.0.1',
    metadata: [
        'channel' => 'alipay',
    ]
));
```

斗拱汇付托管下单示例：

```php
$response = $gateway->create(new PayOrder(
    outTradeNo: '202603150002',
    amount: '68.00',
    subject: 'Huifu Hosting Order',
    notifyUrl: 'https://demo.test/notify/huifu',
    returnUrl: 'https://demo.test/return/huifu',
    scene: 'h5',
    metadata: [
        'mode' => 'hosting',
        'channel' => 'wxpay',
        'project_id' => 'P0000001',
        'request_type' => 'M',
    ]
));
```

斗拱汇付退款示例：

```php
use EasyPay\Payment\Request\RefundOrder;

$refund = $gateway->refund(new RefundOrder(
    outTradeNo: '202603150001',
    refundNo: '202603150101',
    amount: '10.00',
    reason: 'partial refund',
    metadata: [
        'org_hf_seq_id' => '汇付返回的hf_seq_id',
    ]
));
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

查看 [examples/mock-client.php](examples/mock-client.php) 和 [examples/huifu.php](examples/huifu.php)

## License

MIT

