<?php

declare(strict_types=1);

namespace EasyPay\Payment\Support;

final class Header
{
    public static function normalize(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_array($value)
                ? implode(',', $value)
                : (string) $value;
        }

        return $normalized;
    }
}

