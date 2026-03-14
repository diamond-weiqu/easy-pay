<?php

declare(strict_types=1);

namespace EasyPay\Payment\Request;

use EasyPay\Payment\Exception\PaymentException;

final class CloseOrder
{
    public function __construct(
        public string $outTradeNo
    ) {
        if (trim($this->outTradeNo) === '') {
            throw new PaymentException('outTradeNo can not be empty.');
        }
    }
}


