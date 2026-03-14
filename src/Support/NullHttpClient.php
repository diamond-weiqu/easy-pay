<?php

declare(strict_types=1);

namespace EasyPay\Payment\Support;

use EasyPay\Payment\Contracts\HttpClientInterface;
use EasyPay\Payment\Exception\HttpException;

final class NullHttpClient implements HttpClientInterface
{
    public function request(
        string $method,
        string $uri,
        array|string|null $payload = null,
        array $headers = []
    ): array {
        throw new HttpException(sprintf(
            'No HttpClient configured for request [%s] %s.',
            strtoupper($method),
            $uri
        ));
    }
}

