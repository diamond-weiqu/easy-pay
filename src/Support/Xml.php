<?php

declare(strict_types=1);

namespace EasyPay\Payment\Support;

final class Xml
{
    public static function decode(string $xml): array
    {
        $xml = trim($xml);

        if ($xml === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $element = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($element === false) {
            return [];
        }

        $decoded = json_decode(json_encode($element, JSON_UNESCAPED_UNICODE), true);

        return is_array($decoded) ? $decoded : [];
    }
}
