<?php

declare(strict_types=1);

namespace EasyPay\Payment\Support;

use EasyPay\Payment\Exception\InvalidConfigException;
use EasyPay\Payment\Exception\SignatureException;

final class KeyValue
{
    public static function resolve(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (is_file($value)) {
            $content = file_get_contents($value);

            if ($content === false) {
                throw new InvalidConfigException(sprintf('Unable to read key file "%s".', $value));
            }

            return $content;
        }

        return $value;
    }

    public static function require(mixed $value, string $name): string
    {
        $resolved = self::resolve($value);

        if ($resolved === null) {
            throw new InvalidConfigException(sprintf('Missing config key "%s".', $name));
        }

        return $resolved;
    }

    public static function certificateSerialNumber(mixed $value, string $name = 'certificate'): string
    {
        $certificate = self::require($value, $name);
        $parsed = openssl_x509_parse($certificate);

        if (!is_array($parsed) || !isset($parsed['serialNumber'])) {
            throw new SignatureException(sprintf('Unable to parse serial number from %s.', $name));
        }

        return (string) $parsed['serialNumber'];
    }
}
