<?php

namespace App\Tests\Functional\Media;

use App\Tests\Functional\FunctionalTestCase;

final class StudioMediaSecurityTest extends FunctionalTestCase
{
    public function testAnonymousVisitorCannotUploadHikeMedia(): void
    {
        $client = static::createClient();
        $hike = $this->createHikeDraft($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));

        $client->request('POST', sprintf('/admin/studio/hikes/%d/media/photos', $hike->getId()));

        self::assertResponseRedirects('/login');
    }

    public function testRegularUserCannotUploadHikeMedia(): void
    {
        $client = static::createClient();
        $hike = $this->createHikeDraft($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));
        $client->loginUser($this->createUser());

        $client->request('POST', sprintf('/admin/studio/hikes/%d/media/photos', $hike->getId()));

        self::assertResponseRedirects('/');
    }

    public function testVerifiedAdminUploadRequiresCsrfToken(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $hike = $this->createHikeDraft($admin);
        $client->loginUser($admin);

        $client->request('POST', sprintf('/admin/studio/hikes/%d/media/photos', $hike->getId()));

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-photos', $hike->getId()));
    }
}
