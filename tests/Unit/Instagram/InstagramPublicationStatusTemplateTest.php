<?php

namespace App\Tests\Unit\Instagram;

use App\Entity\InstagramPublication;
use App\Enum\InstagramPublicationSourceType;
use App\Enum\InstagramPublicationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class InstagramPublicationStatusTemplateTest extends TestCase
{
    #[DataProvider('statusProvider')]
    public function testItRendersEveryBusinessStatus(
        InstagramPublicationStatus $status,
        string $expectedLabel,
        bool $expectsRetry,
    ): void {
        $publication = $this->publication($status);

        $html = $this->twig()->render('admin/studio/_instagram_publication_status.html.twig', [
            'instagram_publication' => $publication,
        ]);

        self::assertStringContainsString(sprintf('data-instagram-status="%s"', $status->value), $html);
        self::assertStringContainsString($expectedLabel, $html);
        self::assertSame($expectsRetry, str_contains($html, 'data-instagram-retry'));
    }

    /** @return iterable<string, array{InstagramPublicationStatus, string, bool}> */
    public static function statusProvider(): iterable
    {
        yield 'pending' => [InstagramPublicationStatus::Pending, 'Publication programmée', false];
        yield 'processing' => [InstagramPublicationStatus::Processing, 'Publication en cours', false];
        yield 'published' => [InstagramPublicationStatus::Published, 'Publié', false];
        yield 'failed' => [InstagramPublicationStatus::Failed, 'Publication Instagram échouée', true];
        yield 'partial failure' => [InstagramPublicationStatus::PartialFailure, 'Publication Instagram incomplète', true];
        yield 'no media' => [InstagramPublicationStatus::NoMedia, 'Aucune photo compatible à publier', false];
        yield 'legacy skipped' => [InstagramPublicationStatus::LegacySkipped, 'Non envoyé automatiquement sur Instagram', false];
    }

    public function testPublishedStateDisplaysProgressAndDatesWithoutARetryButton(): void
    {
        $publication = $this->publication(InstagramPublicationStatus::Published)
            ->setTotalMediaCount(23)
            ->setPublishedMediaCount(23)
            ->setTotalBatchCount(3)
            ->setPublishedBatchCount(3)
            ->setPublishedAt(new \DateTimeImmutable('2026-04-01 14:30:00+00:00'))
            ->setLastAttemptAt(new \DateTimeImmutable('2026-04-01 14:29:00+00:00'));

        $html = $this->twig()->render('admin/studio/_instagram_publication_status.html.twig', [
            'instagram_publication' => $publication,
        ]);

        self::assertStringContainsString('23 / 23', $html);
        self::assertStringContainsString('3 / 3', $html);
        self::assertStringContainsString('Publié le', $html);
        self::assertStringContainsString('01/04/2026', $html);
        self::assertStringContainsString('Dernière tentative', $html);
        self::assertStringNotContainsString('data-instagram-retry', $html);
    }

    public function testRetryFormUsesTheGenericPostRouteCsrfTokenAndPartialLabel(): void
    {
        $publication = $this->publication(InstagramPublicationStatus::PartialFailure)
            ->setTotalMediaCount(23)
            ->setPublishedMediaCount(10)
            ->setTotalBatchCount(3)
            ->setPublishedBatchCount(1)
            ->setLastAttemptAt(new \DateTimeImmutable('2026-04-02 08:15:00+00:00'))
            ->setLastError('Meta indisponible <script>alert(1)</script> token=secret-value');

        $html = $this->twig()->render('admin/studio/_instagram_publication_status.html.twig', [
            'instagram_publication' => $publication,
        ]);

        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('action="/admin/studio/instagram-publications/41/retry"', $html);
        self::assertStringContainsString('value="csrf:instagram_publication_retry_41"', $html);
        self::assertStringContainsString('value="section-instagram"', $html);
        self::assertStringContainsString('Renvoyer les éléments manquants', $html);
        self::assertStringContainsString('10 / 23', $html);
        self::assertStringContainsString('1 / 3', $html);
        self::assertStringContainsString('token=[redacted]', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testNoMediaIsNotPresentedAsATechnicalFailure(): void
    {
        $html = $this->twig()->render('admin/studio/_instagram_publication_status.html.twig', [
            'instagram_publication' => $this->publication(InstagramPublicationStatus::NoMedia),
        ]);

        self::assertStringContainsString('Aucune photo compatible', $html);
        self::assertStringNotContainsString('Photos publiées', $html);
        self::assertStringNotContainsString('Erreur :', $html);
    }

    public function testItRendersNothingWithoutAPersistedPublication(): void
    {
        self::assertSame('', trim($this->twig()->render(
            'admin/studio/_instagram_publication_status.html.twig',
            ['instagram_publication' => null],
        )));
    }

    public function testPublishedStudioTemplatesIncludeTheSharedPartial(): void
    {
        $projectDirectory = dirname(__DIR__, 3);
        $partialPath = 'admin/studio/_instagram_publication_status.html.twig';

        foreach (['hike_edit.html.twig', 'city_visit_edit.html.twig'] as $templateName) {
            $template = file_get_contents($projectDirectory.'/templates/admin/studio/'.$templateName);
            self::assertIsString($template);
            self::assertStringContainsString("{% include '".$partialPath."'", $template);
            self::assertStringContainsString("['finished', 'converted']", $template);
            self::assertStringContainsString('instagram_publication|default(null)', $template);
        }
    }

    private function publication(InstagramPublicationStatus $status): InstagramPublication
    {
        $publication = (new InstagramPublication(InstagramPublicationSourceType::Hike, 7))
            ->setStatus($status);
        (new \ReflectionProperty($publication, 'id'))->setValue($publication, 41);

        return $publication;
    }

    private function twig(): Environment
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 3).'/templates'),
            ['autoescape' => 'html'],
        );
        $twig->addFunction(new TwigFunction(
            'path',
            static function (string $route, array $parameters = []): string {
                self::assertSame('admin_studio_instagram_retry', $route);
                self::assertSame(41, $parameters['id'] ?? null);

                return '/admin/studio/instagram-publications/41/retry';
            },
        ));
        $twig->addFunction(new TwigFunction(
            'csrf_token',
            static fn(string $tokenId): string => 'csrf:'.$tokenId,
        ));

        return $twig;
    }
}
