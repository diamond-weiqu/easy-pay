<?php

declare(strict_types=1);

namespace EasyPay\Payment\Support;

use EasyPay\Payment\Exception\SignatureException;

final class AlipayCertificate
{
    public static function appCertSerialNumber(mixed $certificate, string $name = 'app_cert'): string
    {
        return self::certificateSerialNumber($certificate, $name);
    }

    public static function rootCertSerialNumber(mixed $certificate, string $name = 'alipay_root_cert'): string
    {
        $content = KeyValue::require($certificate, $name);
        $serialNumbers = [];

        foreach (self::certificates($content) as $pem) {
            $parsed = openssl_x509_parse($pem);

            if (!is_array($parsed)) {
                continue;
            }

            $algorithm = strtolower((string) ($parsed['signatureTypeLN'] ?? $parsed['signatureTypeSN'] ?? ''));
            if (str_contains($algorithm, 'md5') || str_contains($algorithm, 'sha1')) {
                continue;
            }

            $serialNumbers[] = self::serialHash($parsed);
        }

        if ($serialNumbers === []) {
            throw new SignatureException(sprintf('Unable to parse root cert serial number from %s.', $name));
        }

        return implode('_', $serialNumbers);
    }

    public static function publicKey(mixed $certificate, string $name = 'certificate'): string
    {
        $content = KeyValue::require($certificate, $name);
        $pem = self::firstCertificate($content);
        $resource = openssl_pkey_get_public($pem);

        if ($resource === false) {
            throw new SignatureException(sprintf('Unable to read public key from %s.', $name));
        }

        $details = openssl_pkey_get_details($resource);

        if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
            throw new SignatureException(sprintf('Unable to export public key from %s.', $name));
        }

        return $details['key'];
    }

    private static function certificateSerialNumber(mixed $certificate, string $name): string
    {
        $content = KeyValue::require($certificate, $name);
        $pem = self::firstCertificate($content);
        $parsed = openssl_x509_parse($pem);

        if (!is_array($parsed)) {
            throw new SignatureException(sprintf('Unable to parse %s.', $name));
        }

        return self::serialHash($parsed);
    }

    private static function serialHash(array $parsed): string
    {
        $issuer = self::issuerString($parsed['issuer'] ?? []);
        $serial = (string) ($parsed['serialNumber'] ?? '');

        if ($issuer === '' || $serial === '') {
            throw new SignatureException('Invalid certificate issuer or serial number.');
        }

        return md5($issuer . $serial);
    }

    private static function issuerString(array $issuer): string
    {
        if ($issuer === []) {
            return '';
        }

        $pairs = [];
        $issuer = array_reverse($issuer);

        foreach ($issuer as $name => $value) {
            if (is_array($value)) {
                $value = implode('+', $value);
            }

            $pairs[] = sprintf('%s=%s', $name, $value);
        }

        return implode(',', $pairs);
    }

    private static function firstCertificate(string $content): string
    {
        $certificates = self::certificates($content);

        if ($certificates === []) {
            throw new SignatureException('No certificate block found.');
        }

        return $certificates[0];
    }

    private static function certificates(string $content): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $content, $matches);

        return $matches[0] ?? [];
    }
}
