<?php

declare(strict_types=1);

namespace EasyPay\Payment\Support;

use Closure;
use EasyPay\Payment\Contracts\HttpClientInterface;
use UnexpectedValueException;

final class CallableHttpClient implements HttpClientInterface
{
    private Closure $handler;

    public function __construct(callable $handler)
    {
        $this->handler = Closure::fromCallable($handler);
    }

    public function request(
        string $method,
        string $uri,
        array|string|null $payload = null,
        array $headers = []
    ): array {
        $response = ($this->handler)($method, $uri, $payload, $headers);

        if (!is_array($response)) {
            throw new UnexpectedValueException('Callable http client must return array.');
        }

        return $response;
    }
}


