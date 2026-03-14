<?php

declare(strict_types=1);

namespace EasyPay\Payment\Support;

use EasyPay\Payment\Exception\PaymentException;

final class Amount
{
    public static function assert(string $amount): void
    {
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            throw new PaymentException(sprintf('Invalid amount "%s".', $amount));
        }
    }

    public static function toMinorUnits(string $amount): int
    {
        self::assert($amount);

        [$integer, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $integer * 100) + (int) str_pad($decimal, 2, '0');
    }
}

