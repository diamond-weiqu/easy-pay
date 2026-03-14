<?php

declare(strict_types=1);

namespace EasyPay\Payment\Core;

use EasyPay\Payment\Contracts\GatewayInterface;
use EasyPay\Payment\Contracts\HttpClientInterface;
use EasyPay\Payment\Exception\UnsupportedProviderException;
use EasyPay\Payment\Providers\Alipay\AlipayGateway;
use EasyPay\Payment\Providers\Lakala\LakalaGateway;
use EasyPay\Payment\Providers\Leshua\LeshuaGateway;
use EasyPay\Payment\Providers\WechatPay\WechatPayGateway;

final class PaymentManager
{
    public static function make(
        string $provider,
        array|Config $config,
        ?HttpClientInterface $httpClient = null
    ): GatewayInterface {
        return match (strtolower($provider)) {
            'alipay' => new AlipayGateway($config, $httpClient),
            'wechatpay', 'wechat_pay', 'wechat' => new WechatPayGateway($config, $httpClient),
            'leshua' => new LeshuaGateway($config, $httpClient),
            'lakala' => new LakalaGateway($config, $httpClient),
            default => throw new UnsupportedProviderException(sprintf(
                'Unsupported provider "%s".',
                $provider
            )),
        };
    }
}
