<?php

declare(strict_types=1);

namespace EasyPay\Payment\Request;

use EasyPay\Payment\Exception\PaymentException;

final class RefundQuery
{
    public function __construct(
        public string $refundNo,
        public ?string $outTradeNo = null
    ) {
        if (trim($this->refundNo) === '') {
            throw new PaymentException('refundNo can not be empty.');
        }
    }
}


