<?php

namespace App\Tests\E2E;

use App\Entity\Article;
use App\Entity\ArticleMedia;
use App\Entity\MediaAsset;
use App\Enum\ContentStatus;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverWait;

final class ArticleGalleryLayoutPantherTest extends PantherTestCase
{
    public function testGalleryStartsBelowTheCompleteProseAtEveryArticleBreakpoint(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $context = $this->createLongArticleWithInlineImageAndGallery();
        $client = self::createBrowser();
        $driver = $client->getWebDriver();
        $devTools = new ChromeDevToolsDriver($driver);

        try {
            foreach ([
                'grand desktop' => [1680, 1050, 1, false],
                'desktop 1440' => [1440, 1000, 1, false],
                'tablette' => [768, 1024, 1, false],
                'mobile 390' => [390, 844, 2, true],
            ] as $label => [$width, $height, $deviceScaleFactor, $mobile]) {
                $devTools->execute('Emulation.setDeviceMetricsOverride', [
                    'width' => $width,
                    'height' => $height,
                    'deviceScaleFactor' => $deviceScaleFactor,
                    'mobile' => $mobile,
                ]);
                $client->request('GET', $context['path']);
                $client->waitFor('.article-gallery-section .journey-gallery-card');

                /** @var array<string, float|int|string|bool> $layout */
                $layout = (new WebDriverWait($driver, 8))->until(static function () use ($driver): array|false {
                    $data = $driver->executeScript(<<<'JS'
                    const content = document.querySelector('.article-show-main > .article-content');
                    const gallery = document.querySelector('.article-show-main > .article-gallery-section');
                    const grid = gallery?.querySelector('.journey-gallery');
                    const inlineImage = content?.querySelector('.article-content-media-inline');
                    if (!content || !gallery || !grid || !inlineImage) {
                        return null;
                    }

                    const contentRect = content.getBoundingClientRect();
                    const galleryRect = gallery.getBoundingClientRect();
                    const inlineRect = inlineImage.getBoundingClientRect();
                    const cards = Array.from(grid.querySelectorAll('.journey-gallery-card'))
                        .map((card) => card.getBoundingClientRect());

                    return {
                        viewport: window.innerWidth,
                        contentDisplay: getComputedStyle(content).display,
                        galleryClear: getComputedStyle(gallery).clear,
                        galleryPosition: getComputedStyle(gallery).position,
                        galleryTop: galleryRect.top,
                        galleryBottom: galleryRect.bottom,
                        galleryLeft: galleryRect.left,
                        galleryRight: galleryRect.right,
                        contentBottom: contentRect.bottom,
                        inlineBottom: inlineRect.bottom,
                        gap: galleryRect.top - contentRect.bottom,
                        cardCount: cards.length,
                        cardsInsideGallery: cards.every((card) => (
                            card.top >= galleryRect.top
                            && card.bottom <= galleryRect.bottom + 1
                            && card.left >= galleryRect.left - 1
                            && card.right <= galleryRect.right + 1
                        )),
                        overflow: document.documentElement.scrollWidth > window.innerWidth,
                    };
                JS);

                    return is_array($data) && ($data['contentDisplay'] ?? '') === 'flow-root' ? $data : false;
                });

                self::assertSame($width, $layout['viewport'], $label);
                self::assertSame('flow-root', $layout['contentDisplay'], $label);
                self::assertSame('both', $layout['galleryClear'], $label);
                self::assertNotSame('absolute', $layout['galleryPosition'], $label);
                self::assertGreaterThanOrEqual(40.0, (float) $layout['gap'], $label);
                self::assertGreaterThanOrEqual((float) $layout['inlineBottom'], (float) $layout['contentBottom'], $label);
                self::assertGreaterThanOrEqual((float) $layout['contentBottom'], (float) $layout['galleryTop'], $label);
                self::assertSame(3, $layout['cardCount'], $label);
                self::assertTrue((bool) $layout['cardsInsideGallery'], $label);
                self::assertFalse((bool) $layout['overflow'], $label);
            }

            $this->assertNoBrowserSevereErrors($client);
        } finally {
            $devTools->execute('Emulation.clearDeviceMetricsOverride');
            $this->removeTestArticles();
        }
    }

    /** @return array{path: string} */
    private function createLongArticleWithInlineImageAndGallery(): array
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->removeTestArticlesWithEntityManager($entityManager);
        $token = bin2hex(random_bytes(6));
        $article = (new Article())
            ->setTitle('Article galerie sous la prose '.$token)
            ->setSlug('article-galerie-sous-prose-'.$token)
            ->setContent('<p>Contenu en préparation.</p>')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new \DateTimeImmutable('-1 hour'));
        $cover = $this->image('Couverture', '/images/placeholders/destination-card-placeholder.webp', 2200, 1238);
        $inline = $this->image('Illustration verticale', '/images/home/hero-sea-mountain-mobile.webp', 864, 1151);
        $galleryMedia = [
            $this->image('Galerie une', '/images/placeholders/destination-card-placeholder.webp', 2200, 1238),
            $this->image('Galerie deux', '/images/home/hero-sea-mountain-desktop.webp', 1920, 1080),
            $this->image('Galerie trois', '/images/placeholders/destination-card-placeholder.webp', 2200, 1238),
        ];
        $article->setFeaturedImage($cover);
        $entityManager->persist($article);
        $entityManager->persist($cover);
        $entityManager->persist($inline);
        foreach ($galleryMedia as $media) {
            $entityManager->persist($media);
        }
        $entityManager->flush();

        $article->setContent(sprintf(
            '<p>%s</p><p>%s</p><p>Dernier paragraphe avant la galerie avec l’image %s et sa fin de texte.</p>',
            str_repeat('Un long texte éditorial précède naturellement la galerie. ', 18),
            str_repeat('La lecture doit conserver son flux vertical sur chaque largeur. ', 14),
            sprintf('[[media:%d]]', $inline->getId()),
        ));
        $this->link($entityManager, $article, $inline, MediaRole::Content, 0);
        foreach ($galleryMedia as $position => $media) {
            $this->link($entityManager, $article, $media, MediaRole::Gallery, $position + 1);
        }
        $entityManager->flush();
        $path = '/articles/'.$article->getSlug();
        self::ensureKernelShutdown();

        return ['path' => $path];
    }

    private function removeTestArticles(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        if ($entityManager instanceof EntityManagerInterface) {
            $this->removeTestArticlesWithEntityManager($entityManager);
        }
        self::ensureKernelShutdown();
    }

    private function removeTestArticlesWithEntityManager(EntityManagerInterface $entityManager): void
    {
        $articles = $entityManager->getRepository(Article::class)
            ->createQueryBuilder('article')
            ->where('article.slug LIKE :prefix')
            ->setParameter('prefix', 'article-galerie-sous-prose-%')
            ->getQuery()
            ->getResult();

        $mediaToRemove = [];
        foreach ($articles as $article) {
            if ($article instanceof Article) {
                $featuredImage = $article->getFeaturedImage();
                if ($featuredImage instanceof MediaAsset && $featuredImage->getId() !== null) {
                    $mediaToRemove[$featuredImage->getId()] = $featuredImage;
                }
                foreach ($article->getMediaLinks() as $link) {
                    $media = $link->getMediaAsset();
                    if ($media instanceof MediaAsset && $media->getId() !== null) {
                        $mediaToRemove[$media->getId()] = $media;
                    }
                }
                $entityManager->remove($article);
            }
        }
        $entityManager->flush();

        foreach ($mediaToRemove as $media) {
            $entityManager->remove($media);
        }
        $entityManager->flush();
    }

    private function image(string $title, string $path, int $width, int $height): MediaAsset
    {
        return (new MediaAsset())
            ->setTitle($title)
            ->setAltText($title)
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath($path)
            ->setThumbnailPath($path)
            ->setMimeType('image/webp')
            ->setWidth($width)
            ->setHeight($height)
            ->setVariants([
                'thumb' => ['webp' => $path, 'width' => $width, 'height' => $height],
            ]);
    }

    private function link(
        EntityManagerInterface $entityManager,
        Article $article,
        MediaAsset $media,
        MediaRole $role,
        int $position,
    ): void {
        $link = (new ArticleMedia())
            ->setArticle($article)
            ->setMediaAsset($media)
            ->setRole($role)
            ->setPosition($position);
        $article->getMediaLinks()->add($link);
        $media->getArticleLinks()->add($link);
        $entityManager->persist($link);
    }

    private function skipIfFrontendBuildIsMissing(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/public/build/manifest.json')) {
            self::markTestSkipped('Run docker compose run --rm node npm run build before this Panther test.');
        }
    }
}
