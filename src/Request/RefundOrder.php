<?php

declare(strict_types=1);

namespace EasyPay\Payment\Request;

use EasyPay\Payment\Exception\PaymentException;
use EasyPay\Payment\Support\Amount;

final class RefundOrder
{
    public function __construct(
        public string $outTradeNo,
        public string $refundNo,
        public string $amount,
        public ?string $reason = null,
        public ?string $operatorId = null,
        public array $metadata = []
    ) {
        if (trim($this->outTradeNo) === '') {
            throw new PaymentException('outTradeNo can not be empty.');
        }

        if (trim($this->refundNo) === '') {
            throw new PaymentException('refundNo can not be empty.');
        }

        Amount::assert($this->amount);
    }

    public function amountInMinorUnits(): int
    {
        return Amount::toMinorUnits($this->amount);
    }
}


