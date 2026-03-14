<?php

declare(strict_types=1);

namespace EasyPay\Payment\Support;

use EasyPay\Payment\Exception\SignatureException;
use OpenSSLAsymmetricKey;

final class RsaSha256
{
    public static function sign(string $content, string $privateKey): string
    {
        $key = self::privateKey($privateKey);

        $result = openssl_sign($content, $signature, $key, OPENSSL_ALGO_SHA256);

        if ($result !== true) {
            throw new SignatureException('Unable to sign content.');
        }

        return base64_encode($signature);
    }

    public static function verify(string $content, string $signature, string $publicKey): bool
    {
        $key = self::publicKey($publicKey);

        return openssl_verify(
            $content,
            base64_decode($signature, true) ?: '',
            $key,
            OPENSSL_ALGO_SHA256
        ) === 1;
    }

    private static function privateKey(string $privateKey): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private(self::normalizeKey($privateKey, 'PRIVATE KEY'));

        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new SignatureException('Invalid private key.');
        }

        return $key;
    }

    private static function publicKey(string $publicKey): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_public(self::normalizeKey($publicKey, 'PUBLIC KEY'));

        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new SignatureException('Invalid public key.');
        }

        return $key;
    }

    private static function normalizeKey(string $key, string $type): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        $body = trim(chunk_split(str_replace(["\r", "\n", ' '], '', $key), 64, PHP_EOL));

        return sprintf(
            "-----BEGIN %s-----%s%s%s-----END %s-----",
            $type,
            PHP_EOL,
            $body,
            PHP_EOL,
            $type
        );
    }
}

