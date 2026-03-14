<?php

declare(strict_types=1);

namespace EasyPay\Payment\Contracts;

interface HttpClientInterface
{
    public function request(
        string $method,
        string $uri,
        array|string|null $payload = null,
        array $headers = []
    ): array;
}

