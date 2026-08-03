<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class EmailVerificationResendControllerTest extends FunctionalTestCase
{
    private const GENERIC_MESSAGE = 'Si un compte non vérifié correspond à cette adresse, un nouveau lien d’activation vient de lui être envoyé.';

    public function testHistoricalUnverifiedAccountCanRequestLinkSetPasswordAndLogin(): void
    {
        $requester = static::createClient([], ['REMOTE_ADDR' => '198.51.100.10']);
        $this->clearRateLimiterCache();
        $user = $this->createHistoricalUnverifiedUser(['ROLE_ADMIN', 'ROLE_USER']);
        $userId = $this->userId($user);
        $email = (string) $user->getEmail();
        $displayName = $user->getDisplayName();
        $neutralizedPasswordHash = $user->getPassword();

        $this->submitResendRequest($requester, strtoupper($email));

        $this->assertClientRedirects($requester, '/verify/resend');
        self::assertEmailCount(1);
        $verificationUrl = $this->verificationUrlFromEmail();
        $this->assertActivationUrl($verificationUrl, $userId);
        $this->assertPublicResponseContainsNoActivationSecret($requester, $user);

        $requester->request('GET', '/profile');
        $this->assertClientRedirects($requester, '/login');

        $owner = $this->independentClient($requester, '198.51.100.11');
        $newPassword = 'Phrase historique récupérée 2026 9!';
        $this->submitInitialPassword($owner, $verificationUrl, $newPassword);
        $this->assertClientRedirects($owner, '/login');

        $storedUser = $this->storedUser($userId);
        self::assertTrue($storedUser->isVerified());
        self::assertNull($storedUser->getEmailVerificationTokenHash());
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $storedUser->getRoles());
        self::assertSame($displayName, $storedUser->getDisplayName());
        self::assertNotSame($neutralizedPasswordHash, $storedUser->getPassword());
        self::assertTrue($this->passwordHasher()->isPasswordValid($storedUser, $newPassword));

        $loginClient = $this->independentClient($requester, '198.51.100.12');
        $this->attemptPasswordLogin($loginClient, $email, $newPassword);
        $this->assertClientRedirects($loginClient, '/');

        $requester->request('GET', '/profile');
        $this->assertClientRedirects($requester, '/login');
    }

    public function testUnknownVerifiedAndBannedAccountsReceiveTheSameGenericResponseWithoutEmail(): void
    {
        $client = static::createClient([], ['REMOTE_ADDR' => '198.51.100.20']);
        $this->clearRateLimiterCache();
        $verifiedUser = $this->createUser(verified: true);
        $verifiedUser
            ->setEmailVerificationTokenHash(hash('sha256', 'verified-account-sentinel'))
            ->setReceivePublicationEmails(true);
        $bannedUser = $this->createHistoricalUnverifiedUser(banned: true);
        $resendableUser = $this->createHistoricalUnverifiedUser();
        $this->entityManager()->flush();

        $verifiedId = $this->userId($verifiedUser);
        $bannedId = $this->userId($bannedUser);
        $resendableId = $this->userId($resendableUser);
        $verifiedPassword = $verifiedUser->getPassword();
        $verifiedTokenHash = $verifiedUser->getEmailVerificationTokenHash();
        $responses = [];

        foreach ([
            ['unknown-resend-'.bin2hex(random_bytes(6)).'@example.test', 0, '198.51.100.20'],
            [(string) $verifiedUser->getEmail(), 0, '198.51.100.21'],
            [(string) $bannedUser->getEmail(), 0, '198.51.100.22'],
            [(string) $resendableUser->getEmail(), 1, '198.51.100.23'],
        ] as [$email, $expectedEmailCount, $clientIp]) {
            $client->setServerParameter('REMOTE_ADDR', $clientIp);
            $this->submitResendRequest($client, $email);
            self::assertEmailCount($expectedEmailCount);
            $responses[] = $this->genericResponseFingerprint($client, $email);
        }

        self::assertSame($responses[0], $responses[1]);
        self::assertSame($responses[0], $responses[2]);
        self::assertSame($responses[0], $responses[3]);

        $storedVerifiedUser = $this->storedUser($verifiedId);
        self::assertTrue($storedVerifiedUser->isVerified());
        self::assertSame($verifiedPassword, $storedVerifiedUser->getPassword());
        self::assertSame($verifiedTokenHash, $storedVerifiedUser->getEmailVerificationTokenHash());
        self::assertTrue($storedVerifiedUser->isReceivePublicationEmails());

        $storedBannedUser = $this->storedUser($bannedId);
        self::assertTrue($storedBannedUser->isBanned());
        self::assertFalse($storedBannedUser->isVerified());
        self::assertNull($storedBannedUser->getEmailVerificationTokenHash());

        $storedResendableUser = $this->storedUser($resendableId);
        self::assertFalse($storedResendableUser->isVerified());
        self::assertNotNull($storedResendableUser->getEmailVerificationTokenHash());
    }

    public function testGetAndPostWithoutValidCsrfNeverSendOrCreateLink(): void
    {
        $client = static::createClient([], ['REMOTE_ADDR' => '198.51.100.30']);
        $this->clearRateLimiterCache();
        $user = $this->createHistoricalUnverifiedUser();
        $userId = $this->userId($user);

        $crawler = $client->request('GET', '/verify/resend');

        self::assertTrue($client->getResponse()->isSuccessful());
        self::assertSame(1, $crawler->filter('input[name="resend_verification_form[email]"]')->count());
        self::assertSame(1, $crawler->filter('input[name="resend_verification_form[_token]"]')->count());
        self::assertEmailCount(0);

        $client->request('POST', '/verify/resend', [
            'resend_verification_form' => [
                'email' => $user->getEmail(),
                '_token' => 'invalid-csrf-token',
            ],
        ]);

        self::assertTrue($client->getResponse()->isSuccessful());
        self::assertEmailCount(0);
        self::assertNull($this->storedUser($userId)->getEmailVerificationTokenHash());
    }

    public function testSuccessiveResendsInvalidateTheFirstLinkAndConsumptionInvalidatesTheSecond(): void
    {
        $client = static::createClient([], ['REMOTE_ADDR' => '198.51.100.40']);
        $this->clearRateLimiterCache();
        $user = $this->createHistoricalUnverifiedUser();
        $userId = $this->userId($user);

        $this->submitResendRequest($client, (string) $user->getEmail());
        self::assertEmailCount(1);
        $firstUrl = $this->verificationUrlFromEmail();
        $firstTokenHash = $this->storedUser($userId)->getEmailVerificationTokenHash();
        self::assertNotNull($firstTokenHash);

        $this->submitResendRequest($client, (string) $user->getEmail());
        self::assertEmailCount(1);
        $secondUrl = $this->verificationUrlFromEmail();
        $secondTokenHash = $this->storedUser($userId)->getEmailVerificationTokenHash();

        self::assertNotSame($firstUrl, $secondUrl);
        self::assertNotSame($firstTokenHash, $secondTokenHash);
        $this->assertActivationUrl($secondUrl, $userId);

        $firstLinkClient = $this->independentClient($client, '198.51.100.41');
        $firstLinkClient->request('GET', $firstUrl);
        $this->assertClientRedirects($firstLinkClient, '/login');

        $secondLinkClient = $this->independentClient($client, '198.51.100.42');
        $newPassword = 'Phrase second lien unique 2026 9!';
        $this->submitInitialPassword($secondLinkClient, $secondUrl, $newPassword);
        $this->assertClientRedirects($secondLinkClient, '/login');
        self::assertTrue($this->passwordHasher()->isPasswordValid($this->storedUser($userId), $newPassword));

        $replayClient = $this->independentClient($client, '198.51.100.43');
        $replayClient->request('GET', $secondUrl);
        $this->assertClientRedirects($replayClient, '/login');
    }

    public function testHistoricalLinkWithoutPurposeOrActiveServerSignatureRemainsInvalidAfterResend(): void
    {
        $client = static::createClient([], ['REMOTE_ADDR' => '198.51.100.45']);
        $this->clearRateLimiterCache();
        $user = $this->createHistoricalUnverifiedUser();
        $userId = $this->userId($user);
        $helper = static::getContainer()->get(VerifyEmailHelperInterface::class);
        self::assertInstanceOf(VerifyEmailHelperInterface::class, $helper);
        $historicalUrl = $helper->generateSignature(
            'app_verify_email',
            (string) $userId,
            (string) $user->getEmail(),
            ['id' => $userId],
        )->getSignedUrl();

        $this->submitResendRequest($client, (string) $user->getEmail());
        self::assertEmailCount(1);
        $newUrl = $this->verificationUrlFromEmail();

        $historicalLinkClient = $this->independentClient($client, '198.51.100.46');
        $historicalLinkClient->request('GET', $historicalUrl);
        $this->assertClientRedirects($historicalLinkClient, '/login');
        self::assertFalse($this->storedUser($userId)->isVerified());

        $newLinkClient = $this->independentClient($client, '198.51.100.47');
        $newLinkClient->request('GET', $newUrl);
        self::assertTrue($newLinkClient->getResponse()->isSuccessful());
    }

    public function testResendIsLimitedIndependentlyByNormalizedAddress(): void
    {
        $client = static::createClient([], ['REMOTE_ADDR' => '198.51.100.50']);
        $this->clearRateLimiterCache();
        $user = $this->createHistoricalUnverifiedUser();
        $userId = $this->userId($user);
        $lastAcceptedHash = null;

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $client->setServerParameter('REMOTE_ADDR', '198.51.100.'.(50 + $attempt));
            $submittedEmail = $attempt % 2 === 0
                ? strtoupper((string) $user->getEmail())
                : (string) $user->getEmail();
            $this->submitResendRequest($client, $submittedEmail);

            if ($attempt <= 3) {
                self::assertEmailCount(1);
                $lastAcceptedHash = $this->storedUser($userId)->getEmailVerificationTokenHash();
                self::assertNotNull($lastAcceptedHash);
            } else {
                self::assertEmailCount(0);
                self::assertSame($lastAcceptedHash, $this->storedUser($userId)->getEmailVerificationTokenHash());
            }
        }
    }

    public function testResendIsLimitedIndependentlyByClientIp(): void
    {
        $client = static::createClient([], ['REMOTE_ADDR' => '198.51.100.60']);
        $this->clearRateLimiterCache();
        $users = [
            $this->createHistoricalUnverifiedUser(),
            $this->createHistoricalUnverifiedUser(),
            $this->createHistoricalUnverifiedUser(),
            $this->createHistoricalUnverifiedUser(),
        ];

        foreach ($users as $index => $user) {
            $this->submitResendRequest($client, (string) $user->getEmail());

            if ($index < 3) {
                self::assertEmailCount(1);
                self::assertNotNull($this->storedUser($this->userId($user))->getEmailVerificationTokenHash());
            } else {
                self::assertEmailCount(0);
                self::assertNull($this->storedUser($this->userId($user))->getEmailVerificationTokenHash());
            }
        }
    }

    public function testInvalidActivationLinkOffersGenericResendWithoutSignatureDetails(): void
    {
        $client = static::createClient([], ['REMOTE_ADDR' => '198.51.100.70']);

        $client->request('GET', '/verify/email?id=999999&purpose=initial-password&signature=technical-secret');
        $this->assertClientRedirects($client, '/login');
        $crawler = $client->followRedirect();

        self::assertTrue($client->getResponse()->isSuccessful());
        self::assertStringContainsString('Le lien de confirmation est invalide ou a expiré.', $crawler->filter('body')->text());
        self::assertSame(1, $crawler->filter('a[href="/verify/resend"]')->count());
        $content = $client->getResponse()->getContent() ?: '';
        self::assertStringNotContainsString('technical-secret', $content);
        self::assertStringNotContainsString('signature=', $content);
        self::assertStringNotContainsString('purpose=initial-password', $content);
    }

    /** @param list<string> $roles */
    private function createHistoricalUnverifiedUser(array $roles = ['ROLE_USER'], bool $banned = false): User
    {
        $user = $this->createUser($roles, verified: false, banned: $banned);
        $user
            ->setPassword($this->passwordHasher()->hashPassword($user, bin2hex(random_bytes(48))))
            ->setEmailVerificationTokenHash(null);
        $this->entityManager()->flush();

        return $user;
    }

    private function submitResendRequest(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/verify/resend');
        self::assertTrue($client->getResponse()->isSuccessful());
        $client->submit($crawler->selectButton('Renvoyer le lien d’activation')->form([
            'resend_verification_form[email]' => $email,
        ]));
    }

    /** @return array{int, string, string} */
    private function genericResponseFingerprint(KernelBrowser $client, string $submittedEmail): array
    {
        $this->assertClientRedirects($client, '/verify/resend');
        $statusCode = $client->getResponse()->getStatusCode();
        self::assertSame(Response::HTTP_FOUND, $statusCode);
        $redirectContent = $client->getResponse()->getContent() ?: '';
        self::assertStringNotContainsString($submittedEmail, $redirectContent);
        self::assertStringNotContainsString('signature=', $redirectContent);
        self::assertStringNotContainsString('/verify/email?', $redirectContent);

        $crawler = $client->followRedirect();
        self::assertTrue($client->getResponse()->isSuccessful());
        self::assertSame(1, $crawler->filter('.flash-success')->count());
        $message = trim($crawler->filter('.flash-success')->text());
        self::assertSame(self::GENERIC_MESSAGE, $message);

        $content = $client->getResponse()->getContent() ?: '';
        self::assertStringNotContainsString($submittedEmail, $content);
        self::assertStringNotContainsString('signature=', $content);
        self::assertStringNotContainsString('purpose=initial-password', $content);
        self::assertStringNotContainsString('/verify/email?', $content);

        return [$statusCode, '/verify/resend', $message];
    }

    private function verificationUrlFromEmail(): string
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        $htmlBody = $email->getHtmlBody();
        self::assertIsString($htmlBody);
        $crawler = new Crawler($htmlBody);
        $link = $crawler->filter('a[href*="/verify/email?"]')->first();
        self::assertSame(1, $link->count());
        $href = $link->attr('href');
        self::assertIsString($href);

        return html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
    }

    private function assertActivationUrl(string $url, int $userId): void
    {
        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $parameters);

        self::assertSame((string) $userId, (string) ($parameters['id'] ?? ''));
        self::assertSame('initial-password', $parameters['purpose'] ?? null);
        self::assertNotSame('', $parameters['nonce'] ?? '');
        self::assertNotSame('', $parameters['signature'] ?? '');
        self::assertArrayHasKey('expires', $parameters);
        self::assertGreaterThan(time(), (int) $parameters['expires']);
        self::assertLessThanOrEqual(time() + 3600, (int) $parameters['expires']);
    }

    private function assertPublicResponseContainsNoActivationSecret(KernelBrowser $client, User $user): void
    {
        $content = $client->getResponse()->getContent() ?: '';
        self::assertStringNotContainsString((string) $user->getEmail(), $content);
        self::assertStringNotContainsString('id='.$this->userId($user), $content);
        self::assertStringNotContainsString('signature=', $content);
        self::assertStringNotContainsString('purpose=initial-password', $content);
        self::assertStringNotContainsString('/verify/email?', $content);
    }

    private function submitInitialPassword(KernelBrowser $client, string $url, string $password): void
    {
        $crawler = $client->request('GET', $url);
        self::assertTrue($client->getResponse()->isSuccessful());
        $client->submit($crawler->selectButton('Activer mon compte')->form([
            'initial_password_form[plainPassword][first]' => $password,
            'initial_password_form[plainPassword][second]' => $password,
        ]));
    }

    private function attemptPasswordLogin(KernelBrowser $client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/login');
        $client->request('POST', '/login', [
            '_username' => $email,
            '_password' => $password,
            '_csrf_token' => $this->inputValue($crawler, 'input[name="_csrf_token"]'),
        ]);
    }

    private function storedUser(int $userId): User
    {
        $this->entityManager()->clear();
        $user = $this->entityManager()->find(User::class, $userId);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function userId(User $user): int
    {
        $userId = $user->getId();
        self::assertNotNull($userId);

        return $userId;
    }

    private function passwordHasher(): UserPasswordHasherInterface
    {
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        return $passwordHasher;
    }

    private function clearRateLimiterCache(): void
    {
        $cache = static::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        self::assertTrue($cache->clear());
    }

    private function independentClient(KernelBrowser $client, string $clientIp): KernelBrowser
    {
        $independentClient = new KernelBrowser($client->getKernel());
        $independentClient->setServerParameter('REMOTE_ADDR', $clientIp);

        return $independentClient;
    }

    private function assertClientRedirects(KernelBrowser $client, string $expectedPath): void
    {
        $location = $client->getResponse()->headers->get('Location');
        self::assertTrue($client->getResponse()->isRedirection());
        self::assertIsString($location);
        self::assertSame($expectedPath, parse_url($location, PHP_URL_PATH));
    }
}
