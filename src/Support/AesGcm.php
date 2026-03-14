<?php

declare(strict_types=1);

namespace EasyPay\Payment\Support;

use EasyPay\Payment\Exception\SignatureException;

final class AesGcm
{
    public static function decrypt(
        string $ciphertext,
        string $key,
        string $nonce,
        string $associatedData = ''
    ): string {
        $decoded = base64_decode($ciphertext, true);

        if ($decoded === false || strlen($decoded) < 16) {
            throw new SignatureException('Invalid ciphertext.');
        }

        $tag = substr($decoded, -16);
        $cipher = substr($decoded, 0, -16);

        $plainText = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData
        );

        if (!is_string($plainText)) {
            throw new SignatureException('Unable to decrypt ciphertext.');
        }

        return $plainText;
    }
}

