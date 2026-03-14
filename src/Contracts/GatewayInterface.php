<?php

declare(strict_types=1);

namespace EasyPay\Payment\Contracts;

use EasyPay\Payment\Request\CloseOrder;
use EasyPay\Payment\Request\PayOrder;
use EasyPay\Payment\Request\QueryOrder;
use EasyPay\Payment\Request\RefundOrder;
use EasyPay\Payment\Request\RefundQuery;
use EasyPay\Payment\Response\GatewayResponse;
use EasyPay\Payment\Response\ParsedNotify;

interface GatewayInterface
{
    public function create(PayOrder $order): GatewayResponse;

    public function query(QueryOrder $query): GatewayResponse;

    public function close(CloseOrder $order): GatewayResponse;

    public function refund(RefundOrder $order): GatewayResponse;

    public function refundQuery(RefundQuery $query): GatewayResponse;

    public function parseNotify(string $body, array $headers = []): ParsedNotify;
}

