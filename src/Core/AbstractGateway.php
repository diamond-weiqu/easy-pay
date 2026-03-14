<?php

declare(strict_types=1);

namespace EasyPay\Payment\Core;

use EasyPay\Payment\Contracts\HttpClientInterface;
use EasyPay\Payment\Exception\HttpException;
use EasyPay\Payment\Response\GatewayResponse;
use EasyPay\Payment\Support\NullHttpClient;

abstract class AbstractGateway
{
    protected Config $config;

    protected HttpClientInterface $httpClient;

    public function __construct(array|Config $config, ?HttpClientInterface $httpClient = null)
    {
        $this->config = $config instanceof Config ? $config : Config::fromArray($config);
        $this->httpClient = $httpClient ?? new NullHttpClient();
    }

    protected function config(): Config
    {
        return $this->config;
    }

    protected function send(
        string $provider,
        string $operation,
        string $method,
        string $uri,
        array|string|null $payload = null,
        array $headers = []
    ): GatewayResponse {
        try {
            $data = $this->httpClient->request($method, $uri, $payload, $headers);
        } catch (HttpException $exception) {
            return GatewayResponse::failure(
                provider: $provider,
                operation: $operation,
                request: [
                    'method' => $method,
                    'uri' => $uri,
                    'headers' => $headers,
                    'payload' => $payload,
                ],
                message: $exception->getMessage()
            );
        }

        return GatewayResponse::success(
            provider: $provider,
            operation: $operation,
            request: [
                'method' => $method,
                'uri' => $uri,
                'headers' => $headers,
                'payload' => $payload,
            ],
            data: $data
        );
    }
}


