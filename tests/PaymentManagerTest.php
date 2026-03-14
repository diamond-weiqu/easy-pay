<?php

declare(strict_types=1);

namespace EasyPay\Payment\Tests;

use EasyPay\Payment\Core\PaymentManager;
use EasyPay\Payment\Providers\Alipay\AlipayGateway;
use EasyPay\Payment\Providers\Lakala\LakalaGateway;
use EasyPay\Payment\Providers\Leshua\LeshuaGateway;
use EasyPay\Payment\Providers\WechatPay\WechatPayGateway;
use PHPUnit\Framework\TestCase;

final class PaymentManagerTest extends TestCase
{
    public function testCreateAlipayGateway(): void
    {
        self::assertInstanceOf(AlipayGateway::class, PaymentManager::make('alipay', ['app_id' => 'demo']));
    }

    public function testCreateWechatGateway(): void
    {
        self::assertInstanceOf(WechatPayGateway::class, PaymentManager::make('wechat', [
            'app_id' => 'wx-demo',
            'mch_id' => 'm-demo',
        ]));
    }

    public function testCreateLeshuaGateway(): void
    {
        self::assertInstanceOf(LeshuaGateway::class, PaymentManager::make('leshua', [
            'merchant_id' => 'm123',
            'trade_key' => 'k123',
            'notify_key' => 'n123',
        ]));
    }

    public function testCreateLakalaGateway(): void
    {
        self::assertInstanceOf(LakalaGateway::class, PaymentManager::make('lakala', [
            'app_id' => 'app-demo',
            'merchant_no' => 'm-demo',
            'term_no' => 't-demo',
        ]));
    }
}
