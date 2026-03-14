<?php

declare(strict_types=1);

namespace EasyPay\Payment\Request;

use EasyPay\Payment\Exception\PaymentException;
use EasyPay\Payment\Support\Amount;

final class PayOrder
{
    public function __construct(
        public string $outTradeNo,
        public string $amount,
        public string $subject,
        public ?string $description = null,
        public ?string $notifyUrl = null,
        public ?string $returnUrl = null,
        public string $currency = 'CNY',
        public string $scene = 'native',
        public ?string $clientIp = null,
        public ?string $openid = null,
        public array $metadata = []
    ) {
        if (trim($this->outTradeNo) === '') {
            throw new PaymentException('outTradeNo can not be empty.');
        }

        if (trim($this->subject) === '') {
            throw new PaymentException('subject can not be empty.');
        }

        Amount::assert($this->amount);
    }

    public function descriptionText(): string
    {
        return $this->description ?: $this->subject;
    }

    public function amountInMinorUnits(): int
    {
        return Amount::toMinorUnits($this->amount);
    }
}


