<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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

    public function testUnverifiedRoleAdminIsBlockedOnAdmin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));

        $client->request('GET', '/admin');

        self::assertResponseRedirects('/');
    }

    public function testVerifiedRoleAdminIsAuthorizedOnAdmin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER'], true));

        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
    }

    public function testAdminPostWithoutCsrfIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER'], true));

        $client->request('POST', '/admin/media/new', [
            'mediaType' => 'image',
            'imageType' => 'standard',
            'title' => 'Image invalide',
            'filePath' => '/uploads/media/test.jpg',
        ]);

        self::assertResponseRedirects('/');
    }

    public function testAdminMediaFilePathTraversalIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER'], true));
        $token = $this->adminMediaCsrfToken($client);
        $client->catchExceptions(false);

        $this->expectException(BadRequestHttpException::class);

        $client->request('POST', '/admin/media/new', [
            '_token' => $token,
            'mediaType' => 'image',
            'imageType' => 'standard',
            'title' => 'Image invalide',
            'filePath' => '/uploads/../.env',
        ]);

    }

    public function testAdminMediaJavascriptExternalUrlIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER'], true));
        $token = $this->adminMediaCsrfToken($client);
        $client->catchExceptions(false);

        $this->expectException(BadRequestHttpException::class);

        $client->request('POST', '/admin/media/new', [
            '_token' => $token,
            'mediaType' => 'video',
            'videoType' => 'external',
            'title' => 'Vidéo invalide',
            'externalUrl' => 'javascript:alert(1)',
        ]);

    }

    /**
     * @param list<string> $roles
     */
    private function createUser(array $roles, bool $verified = false): User
    {
        $uniqueToken = bin2hex(random_bytes(6));
        $user = (new User())
            ->setEmail(sprintf('%s-%s@example.test', strtolower(str_replace('ROLE_', '', $roles[0])), $uniqueToken))
            ->setDisplayName('Utilisateur test '.$uniqueToken)
            ->setRoles($roles)
            ->setIsVerified($verified)
            ->setPassword('test-password');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function adminMediaCsrfToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin/media/new');
        self::assertResponseIsSuccessful();

        return $crawler->filter('input[name="_token"]')->attr('value');
    }
}
