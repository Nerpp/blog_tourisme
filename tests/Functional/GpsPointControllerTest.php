<?php

namespace App\Tests\Functional;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GpsPointControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorIsDeniedGpsPointOpen(): void
    {
        $client = static::createClient();
        $point = $this->createHikePoint($this->createPublishedHike($this->createVerifiedAdmin()));

        $client->request('GET', sprintf('/gps/points/hike/%d/open', $point->getId()));

        self::assertResponseRedirects('/login');
    }

    public function testLoggedInUserCanOpenPublishedHikePointInGoogleMaps(): void
    {
        $client = static::createClient();
        $point = $this->createHikePoint($this->createPublishedHike($this->createVerifiedAdmin()), 42.5, 2.75);
        $client->loginUser($this->createUser());

        $client->request('GET', sprintf('/gps/points/hike/%d/open', $point->getId()));

        self::assertResponseRedirects('https://www.google.com/maps/search/?api=1&query=42.5,2.75');
    }

    public function testLoggedInUserCanOpenPublishedCityVisitPointInGoogleMaps(): void
    {
        $client = static::createClient();
        $point = $this->createCityVisitPoint($this->createPublishedCityVisit($this->createVerifiedAdmin()), 43.61, 3.89);
        $client->loginUser($this->createUser());

        $client->request('GET', sprintf('/gps/points/city_visit/%d/open', $point->getId()));

        self::assertResponseRedirects('https://www.google.com/maps/search/?api=1&query=43.61,3.89');
    }

    public function testDraftContentGpsPointIsNotVisible(): void
    {
        $client = static::createClient();
        $point = $this->createHikePoint($this->createHikeDraft($this->createVerifiedAdmin()));
        $client->loginUser($this->createUser());
        $client->catchExceptions(false);

        $this->expectException(NotFoundHttpException::class);

        $client->request('GET', sprintf('/gps/points/hike/%d/open', $point->getId()));
    }

    public function testDraftContentStartRouteIsNotVisible(): void
    {
        $client = static::createClient();
        $hike = $this->createHikeDraft($this->createVerifiedAdmin());
        $this->createHikePoint($hike, 42.5, 2.75);
        $client->loginUser($this->createUser());
        $client->catchExceptions(false);

        $this->expectException(NotFoundHttpException::class);

        $client->request('GET', sprintf('/gps/hike/%d/start', $hike->getId()));
    }

    public function testLoggedInUserCanOpenHikeStartDirections(): void
    {
        $client = static::createClient();
        $hike = $this->createPublishedHike($this->createVerifiedAdmin());
        $this->createHikePoint($hike, 42.5, 2.75, 1);
        $client->loginUser($this->createUser());

        $client->request('GET', sprintf('/gps/hike/%d/start', $hike->getId()));

        self::assertResponseRedirects(null, 302);
        $query = $this->googleMapsQuery($client->getResponse()->headers->get('Location') ?? '');
        self::assertSame('1', $query['api'] ?? null);
        self::assertSame('walking', $query['travelmode'] ?? null);
        self::assertSame('42.5,2.75', $query['destination'] ?? null);
        self::assertArrayNotHasKey('origin', $query);
    }

    public function testLoggedInUserCanOpenHikeRouteWithWaypointLimit(): void
    {
        $client = static::createClient();
        $hike = $this->createPublishedHike($this->createVerifiedAdmin());
        for ($position = 1; $position <= 25; ++$position) {
            $this->createHikePoint($hike, 42 + ($position / 100), 2 + ($position / 100), $position);
        }
        $client->loginUser($this->createUser());

        $client->request('GET', sprintf('/gps/hike/%d/route', $hike->getId()));

        self::assertResponseRedirects(null, 302);
        $query = $this->googleMapsQuery($client->getResponse()->headers->get('Location') ?? '');
        self::assertSame('42.01,2.01', $query['origin'] ?? null);
        self::assertSame('42.25,2.25', $query['destination'] ?? null);
        self::assertArrayHasKey('waypoints', $query);
        $waypoints = explode('|', (string) $query['waypoints']);
        self::assertCount(20, $waypoints);
        self::assertSame('42.02,2.02', $waypoints[0]);
        self::assertSame('42.21,2.21', $waypoints[19]);
    }

    public function testLoggedInUserCanOpenCityVisitRoute(): void
    {
        $client = static::createClient();
        $cityVisit = $this->createPublishedCityVisit($this->createVerifiedAdmin());
        $this->createCityVisitPoint($cityVisit, 43.6, 3.88, 1);
        $this->createCityVisitPoint($cityVisit, 43.61, 3.89, 2);
        $client->loginUser($this->createUser());

        $client->request('GET', sprintf('/gps/city_visit/%d/route', $cityVisit->getId()));

        self::assertResponseRedirects(null, 302);
        self::assertStringStartsWith('https://www.google.com/maps/dir/?', $client->getResponse()->headers->get('Location') ?? '');
    }

    /**
     * @return array<string, string>
     */
    private function googleMapsQuery(string $url): array
    {
        self::assertStringStartsWith('https://www.google.com/maps/dir/?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return array_filter($query, static fn (mixed $value): bool => is_string($value));
    }
}
