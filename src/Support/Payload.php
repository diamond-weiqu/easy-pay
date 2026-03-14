<?php

declare(strict_types=1);

namespace EasyPay\Payment\Support;

final class Payload
{
    public static function filter(array $payload, array $exclude = []): array
    {
        $filtered = [];

        foreach ($payload as $key => $value) {
            if (in_array($key, $exclude, true)) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    public static function sort(array $payload): array
    {
        ksort($payload);

        return $payload;
    }

    public static function queryString(array $payload, array $exclude = []): string
    {
        $pairs = [];

        foreach (self::sort(self::filter($payload, $exclude)) as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $pairs[] = sprintf('%s=%s', $key, $value);
        }

        return implode('&', $pairs);
    }

    public static function json(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}

