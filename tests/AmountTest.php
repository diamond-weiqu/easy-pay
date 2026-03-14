<?php

declare(strict_types=1);

namespace EasyPay\Payment\Tests;

use EasyPay\Payment\Support\Amount;
use PHPUnit\Framework\TestCase;

final class AmountTest extends TestCase
{
    public function testToMinorUnits(): void
    {
        self::assertSame(100, Amount::toMinorUnits('1.00'));
        self::assertSame(105, Amount::toMinorUnits('1.05'));
        self::assertSame(2000, Amount::toMinorUnits('20'));
    }
}

