<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminSecurityTest extends WebTestCase
{
    public function testAnonymousUserIsBlockedOnAdmin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }

    public function testRoleUserIsBlockedOnAdmin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_USER']));

        $client->request('GET', '/admin');

        self::assertResponseRedirects('/');
    }

    public function testRoleAdminIsAuthorizedOnAdmin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));

        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
    }

    public function testAdminPostWithoutCsrfIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));

        $client->request('POST', '/admin/media/new', [
            'mediaType' => 'image',
            'imageType' => 'standard',
            'title' => 'Image invalide',
            'filePath' => '/uploads/media/test.jpg',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminMediaFilePathTraversalIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));
        $token = $this->adminMediaCsrfToken($client);

        $client->request('POST', '/admin/media/new', [
            '_token' => $token,
            'mediaType' => 'image',
            'imageType' => 'standard',
            'title' => 'Image invalide',
            'filePath' => '/uploads/../.env',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testAdminMediaJavascriptExternalUrlIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));
        $token = $this->adminMediaCsrfToken($client);

        $client->request('POST', '/admin/media/new', [
            '_token' => $token,
            'mediaType' => 'video',
            'videoType' => 'external',
            'title' => 'Vidéo invalide',
            'externalUrl' => 'javascript:alert(1)',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(array $roles): User
    {
        return (new User())
            ->setEmail(sprintf('%s@example.test', strtolower(str_replace('ROLE_', '', $roles[0]))))
            ->setDisplayName('Utilisateur test')
            ->setRoles($roles);
    }

    private function adminMediaCsrfToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin/media/new');
        self::assertResponseIsSuccessful();

        return $crawler->filter('input[name="_token"]')->attr('value');
    }
}
