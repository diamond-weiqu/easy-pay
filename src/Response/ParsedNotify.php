<?php

declare(strict_types=1);

namespace EasyPay\Payment\Response;

final class ParsedNotify
{
    public function __construct(
        public string $provider,
        public string $event,
        public string $outTradeNo,
        public ?string $tradeNo = null,
        public ?string $status = null,
        public ?string $amount = null,
        public array $data = [],
        public array $raw = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'event' => $this->event,
            'out_trade_no' => $this->outTradeNo,
            'trade_no' => $this->tradeNo,
            'status' => $this->status,
            'amount' => $this->amount,
            'data' => $this->data,
            'raw' => $this->raw,
        ];
    }
}


