<?php

namespace App\Tests\Unit\Weather;

use App\Service\Weather\QfeCalculator;
use PHPUnit\Framework\TestCase;

final class QfeCalculatorTest extends TestCase
{
    public function testItCalculatesQfeFromQnhAndAltitude(): void
    {
        $calculator = new QfeCalculator();

        self::assertSame(1004, $calculator->calculate(1016, 100));
        self::assertSame(1013, $calculator->calculate(1013, 0));
    }
}
