<?php

namespace App\Tests\Functional;

use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Twig\Environment;

final class TranslationTest extends WebTestCase
{
    public function testDefaultLocaleAndResponseContentLanguageAreFrench(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('fr', static::getContainer()->getParameter('kernel.default_locale'));
        self::assertSame(['fr', 'en'], static::getContainer()->getParameter('kernel.enabled_locales'));
        self::assertSame('fr', $client->getResponse()->headers->get('Content-Language'));
    }

    public function testStandardValidatorMessageIsFrench(): void
    {
        $validator = static::getContainer()->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        $violations = $validator->validate('', new NotBlank());

        self::assertCount(1, $violations);
        self::assertSame('Cette valeur ne doit pas être vide.', (string) $violations[0]->getMessage());
    }

    public function testCustomValidatorMessageIsFrench(): void
    {
        $validator = static::getContainer()->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        $violations = $validator->validateProperty(new User(), 'displayName');

        self::assertCount(1, $violations);
        self::assertSame('Veuillez choisir un nom affiché.', (string) $violations[0]->getMessage());
    }

    public function testInvalidLoginMessageIsFrench(): void
    {
        $client = static::createClient();
        $rateLimiterCache = static::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();

        $crawler = $client->request('GET', '/login');
        $csrfToken = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/login', [
            '_username' => sprintf('unknown-%s@example.test', bin2hex(random_bytes(6))),
            '_password' => 'invalid-password',
            '_csrf_token' => $csrfToken,
        ]);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.login-error', 'Identifiants invalides.');
    }

    #[DataProvider('frenchPublicRouteProvider')]
    public function testExistingFrenchPublicRoutesRemainAvailable(string $path): void
    {
        $client = static::createClient();

        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{string}> */
    public static function frenchPublicRouteProvider(): iterable
    {
        yield 'articles' => ['/articles'];
        yield 'destinations' => ['/destinations'];
        yield 'places' => ['/places'];
        yield 'randonnées' => ['/randonnees'];
        yield 'visites' => ['/visites'];
    }

    #[DataProvider('publicErrorTemplateProvider')]
    public function testPublicErrorTemplatesAreFrenchAndHideTechnicalDetails(int $statusCode, string $title): void
    {
        static::createClient();
        $requestStack = static::getContainer()->get(RequestStack::class);
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        self::assertInstanceOf(Environment::class, $twig);

        $requestStack->push(Request::create('/erreur'));
        try {
            $html = $twig->render(sprintf('@Twig/Exception/error%d.html.twig', $statusCode), [
                'status_code' => $statusCode,
                'status_text' => $title,
            ]);
        } finally {
            $requestStack->pop();
        }

        self::assertStringContainsString($title, $html);
        self::assertStringNotContainsString('Stack trace', $html);
        self::assertStringNotContainsString('RouterListener.php', $html);
    }

    /** @return iterable<string, array{int, string}> */
    public static function publicErrorTemplateProvider(): iterable
    {
        yield '403' => [403, 'Accès refusé'];
        yield '404' => [404, 'Page introuvable'];
        yield '500' => [500, 'Une erreur est survenue'];
    }
}
