<?php

namespace App\Tests\Unit\Service;

use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use App\Service\Hike\GoogleMapsHikeRouteUrlGenerator;
use PHPUnit\Framework\TestCase;

final class GoogleMapsHikeRouteUrlGeneratorTest extends TestCase
{
    private GoogleMapsHikeRouteUrlGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new GoogleMapsHikeRouteUrlGenerator();
    }

    public function testReturnsNullWithoutPoints(): void
    {
        self::assertNull($this->generator->generate(new HikeDraft()));
    }

    public function testReturnsSearchUrlForOneValidPoint(): void
    {
        $hike = new HikeDraft();
        $this->addPoint($hike, 42.1234, 2.5678, 1);

        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=42.1234,2.5678',
            $this->generator->generate($hike),
        );
    }

    public function testReturnsWalkingDirectionsForTwoPointsWithoutWaypoint(): void
    {
        $hike = new HikeDraft();
        $this->addPoint($hike, 42.1, 2.1, 1);
        $this->addPoint($hike, 42.2, 2.2, 2);

        self::assertSame(
            'https://www.google.com/maps/dir/?api=1&travelmode=walking&origin=42.1%2C2.1&destination=42.2%2C2.2',
            $this->generator->generate($hike),
        );
    }

    public function testSortsSeveralPointsAndEncodesEveryWaypoint(): void
    {
        $hike = new HikeDraft();
        $this->addPoint($hike, 42.4, 2.4, 4);
        $this->addPoint($hike, 42.2, 2.2, 2);
        $this->addPoint($hike, 42.1, 2.1, 1);
        $this->addPoint($hike, 42.3, 2.3, 3);

        self::assertSame(
            'https://www.google.com/maps/dir/?api=1&travelmode=walking&origin=42.1%2C2.1&destination=42.4%2C2.4&waypoints=42.2%2C2.2%7C42.3%2C2.3',
            $this->generator->generate($hike),
        );
    }

    public function testIgnoresNullAndOutOfRangeCoordinates(): void
    {
        $hike = new HikeDraft();
        $this->addPoint($hike, null, 2.0, 1);
        $this->addPoint($hike, 91.0, 2.1, 2);
        $this->addPoint($hike, 42.2, -181.0, 3);
        $this->addPoint($hike, 42.3, 2.3, 4);

        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=42.3,2.3',
            $this->generator->generate($hike),
        );
    }

    private function addPoint(
        HikeDraft $hike,
        ?float $latitude,
        ?float $longitude,
        int $position,
    ): void {
        $hike->addPoint(
            (new HikePoint())
                ->setLatitude($latitude)
                ->setLongitude($longitude)
                ->setPosition($position),
        );
    }
}
