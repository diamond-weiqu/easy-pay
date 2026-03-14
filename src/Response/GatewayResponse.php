<?php

declare(strict_types=1);

namespace EasyPay\Payment\Response;

final class GatewayResponse
{
    public function __construct(
        public bool $success,
        public string $provider,
        public string $operation,
        public array $request = [],
        public array $data = [],
        public ?string $message = null
    ) {
    }

    public static function success(
        string $provider,
        string $operation,
        array $request = [],
        array $data = []
    ): self {
        return new self(
            success: true,
            provider: $provider,
            operation: $operation,
            request: $request,
            data: $data
        );
    }

    public static function failure(
        string $provider,
        string $operation,
        array $request = [],
        ?string $message = null,
        array $data = []
    ): self {
        return new self(
            success: false,
            provider: $provider,
            operation: $operation,
            request: $request,
            data: $data,
            message: $message
        );
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'provider' => $this->provider,
            'operation' => $this->operation,
            'request' => $this->request,
            'data' => $this->data,
            'message' => $this->message,
        ];
    }
}


