<?php

namespace App\Service\Weather;

final class QfeCalculator
{
    public function calculate(float $qnhHpa, float $altitudeMeters): int
    {
        return (int) round($qnhHpa - ($altitudeMeters / 8.3));
    }
}
