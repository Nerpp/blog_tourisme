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

    public function testDraftContentGpsPointIsNotVisible(): void
    {
        $client = static::createClient();
        $point = $this->createHikePoint($this->createHikeDraft($this->createVerifiedAdmin()));
        $client->loginUser($this->createUser());
        $client->catchExceptions(false);

        $this->expectException(NotFoundHttpException::class);

        $client->request('GET', sprintf('/gps/points/hike/%d/open', $point->getId()));
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
}
