<?php

declare(strict_types=1);

namespace EasyPay\Payment\Request;

use EasyPay\Payment\Exception\PaymentException;

final class QueryOrder
{
    public function __construct(
        public ?string $outTradeNo = null,
        public ?string $tradeNo = null
    ) {
        if (($this->outTradeNo === null || trim($this->outTradeNo) === '')
            && ($this->tradeNo === null || trim($this->tradeNo) === '')) {
            throw new PaymentException('outTradeNo or tradeNo must be provided.');
        }
    }
}


