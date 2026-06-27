<?php

namespace App\Tests\Unit\Twig;

use App\Entity\Article;
use App\Entity\ArticleMedia;
use App\Entity\MediaAsset;
use App\Enum\ContentStatus;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Service\Article\ArticleContentSanitizer;
use App\Service\Media\MediaSeoTextService;
use App\Twig\ArticleContentExtension;
use App\Twig\MediaImageExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ArticleContentExtensionTest extends TestCase
{
    public function testRegistersSafeTwigFunctions(): void
    {
        $functions = $this->extension()->getFunctions();

        self::assertSame('article_content_html', $functions[0]->getName());
        self::assertSame(['html'], $functions[0]->getSafe(new \Twig\Node\Node()));
        self::assertSame('article_editor_content_html', $functions[1]->getName());
        self::assertSame(['html'], $functions[1]->getSafe(new \Twig\Node\Node()));
    }

    public function testEditorContentSanitizesArticleHtml(): void
    {
        $article = $this->article('<p onclick="alert(1)">Texte <script>alert(1)</script><a href="javascript:alert(1)">lien</a></p>');

        self::assertSame('<p>Texte lien</p>', $this->extension()->editorContentHtml($article));
    }

    public function testContentHtmlRendersIsolatedLinkedImagePlaceholderAsResponsiveFigure(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/uploads/media/original.jpg')
            ->setTitle('Vue du sentier')
            ->setAltText('Vue detaillee du sentier')
            ->setVariants([
                'large' => [
                    'webp' => '/uploads/media/large.webp',
                    'width' => 1200,
                    'height' => 800,
                ],
            ]);
        $this->setEntityId($media, 77);
        $article = $this->article('<p>Avant</p><p>[[media:77]]</p><p>apres</p>');
        $article->getMediaLinks()->add((new ArticleMedia())->setArticle($article));
        $link = (new ArticleMedia())->setArticle($article)->setMediaAsset($media);
        $article->getMediaLinks()->add($link);

        $html = $this->extension()->contentHtml($article);

        self::assertStringContainsString('<figure class="article-content-media"><picture>', $html);
        self::assertStringNotContainsString('image/avif', $html);
        self::assertStringNotContainsString('srcset="/uploads/media/large.jpg', $html);
        self::assertStringContainsString('<img src="/uploads/media/large.webp" alt="Vue detaillee du sentier"', $html);
        self::assertStringContainsString('srcset="/uploads/media/large.webp 1200w"', $html);
        self::assertStringContainsString('width="1200" height="800"', $html);
        self::assertStringContainsString('<figcaption>Vue du sentier</figcaption>', $html);
        self::assertStringNotContainsString('[[media:77]]', $html);
        self::assertStringNotContainsString('<p><figure', $html);
    }

    public function testContentHtmlRendersInlineMediaInsideParagraphAsValidInlineImage(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setTitle('Vue du sentier')
            ->setAltText('Vue detaillee du sentier')
            ->setVariants([
                'medium' => [
                    'webp' => '/uploads/media/medium.webp',
                    'width' => 1200,
                    'height' => 800,
                ],
            ]);
        $this->setEntityId($media, 77);
        $article = $this->article('<p>Avant [[media:77]] apres</p>');
        $article->getMediaLinks()->add((new ArticleMedia())->setArticle($article)->setMediaAsset($media));

        $html = $this->extension()->contentHtml($article);

        self::assertStringContainsString('<p>Avant <span class="article-content-media-inline"><img src="/uploads/media/medium.webp"', $html);
        self::assertStringContainsString('sizes="(min-width: 900px) 320px, 100vw"', $html);
        self::assertStringContainsString('width="1200" height="800"', $html);
        self::assertStringContainsString(' apres</p>', $html);
        self::assertStringNotContainsString('<figure', $html);
        self::assertStringNotContainsString('[[media:77]]', $html);
    }

    public function testContentHtmlUsesMediumVariantForStandardArticleImages(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setTitle('Illustration article')
            ->setAltText('Illustration optimisée')
            ->setVariants([
                'thumb' => ['webp' => '/uploads/media/thumb.webp', 'width' => 600, 'height' => 338],
                'mobile' => ['webp' => '/uploads/media/mobile.webp', 'width' => 960, 'height' => 540],
                'medium' => ['webp' => '/uploads/media/medium.webp', 'width' => 1600, 'height' => 900],
                'large' => ['webp' => '/uploads/media/large.webp', 'width' => 1920, 'height' => 1080],
            ]);
        $this->setEntityId($media, 78);
        $article = $this->article('<p>Avant [[media:78]] apres</p>');
        $article->getMediaLinks()->add((new ArticleMedia())->setArticle($article)->setMediaAsset($media));

        $html = $this->extension()->contentHtml($article);

        self::assertStringContainsString('<img src="/uploads/media/medium.webp" alt="Illustration optimisée"', $html);
        self::assertStringContainsString('srcset="/uploads/media/thumb.webp 600w, /uploads/media/mobile.webp 960w, /uploads/media/medium.webp 1600w"', $html);
        self::assertStringNotContainsString('/uploads/media/large.webp 1920w', $html);
        self::assertStringContainsString('sizes="(min-width: 900px) 320px, 100vw"', $html);
        self::assertStringContainsString('width="1600" height="900"', $html);
    }

    public function testContentHtmlUsesSingleWebpArticleImageWithoutResponsiveVariants(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/uploads/media/article-single.webp')
            ->setThumbnailPath('/uploads/media/article-single.webp')
            ->setMimeType('image/webp')
            ->setWidth(1600)
            ->setHeight(900)
            ->setTitle('Illustration unique')
            ->setAltText('Illustration unique optimisée')
            ->setMetadata(['articleOptimizedSingleWebp' => true]);
        $this->setEntityId($media, 79);
        $article = $this->article('<p>[[media:79]]</p>');
        $article->getMediaLinks()->add((new ArticleMedia())->setArticle($article)->setMediaAsset($media));

        $html = $this->extension()->contentHtml($article);

        self::assertStringContainsString('<img src="/uploads/media/article-single.webp" alt="Illustration unique optimisée"', $html);
        self::assertStringContainsString('srcset="/uploads/media/article-single.webp 1600w"', $html);
        self::assertStringContainsString('width="1600" height="900"', $html);
        self::assertStringNotContainsString('/uploads/media/variants/', $html);
    }

    public function testContentHtmlDropsUnknownOrNonImageMediaPlaceholder(): void
    {
        $video = (new MediaAsset())->setMediaType(MediaType::Video)->setExternalUrl('https://youtu.be/abcDEF_1234');
        $this->setEntityId($video, 88);
        $article = $this->article('<p>Avant [[media:88]] [[media:999]] apres</p>');
        $article->getMediaLinks()->add((new ArticleMedia())->setArticle($article)->setMediaAsset($video));

        self::assertSame('<p>Avant   apres</p>', $this->extension()->contentHtml($article));
    }

    public function testContentHtmlReturnsEmptyForEmptyContent(): void
    {
        self::assertSame('', $this->extension()->contentHtml($this->article('')));
    }

    private function extension(): ArticleContentExtension
    {
        $mediaSeo = new MediaSeoTextService(new AsciiSlugger('fr'));
        $mediaImageExtension = new MediaImageExtension(
            new Packages(new Package(new EmptyVersionStrategy())),
            $mediaSeo,
        );

        return new ArticleContentExtension(new ArticleContentSanitizer(), $mediaImageExtension);
    }

    private function article(string $content): Article
    {
        return (new Article())
            ->setTitle('Article test')
            ->setSlug('article-test')
            ->setContent($content)
            ->setStatus(ContentStatus::Published);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
