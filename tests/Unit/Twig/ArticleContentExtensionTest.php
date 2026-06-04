<?php

namespace App\Tests\Unit\Twig;

use App\Entity\Article;
use App\Entity\ArticleMedia;
use App\Entity\MediaAsset;
use App\Enum\ContentStatus;
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

    public function testContentHtmlReplacesLinkedImagePlaceholderWithResponsiveFigure(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/original.jpg')
            ->setTitle('Vue du sentier')
            ->setAltText('Vue detaillee du sentier')
            ->setVariants([
                'large' => [
                    'fallback' => '/uploads/media/large.jpg',
                    'webp' => '/uploads/media/large.webp',
                    'avif' => '/uploads/media/large.avif',
                    'width' => 1200,
                    'height' => 800,
                ],
            ]);
        $this->setEntityId($media, 77);
        $article = $this->article('<p>Avant [[media:77]] apres</p>');
        $link = (new ArticleMedia())->setArticle($article)->setMediaAsset($media);
        $article->getMediaLinks()->add($link);

        $html = $this->extension()->contentHtml($article);

        self::assertStringContainsString('<figure class="article-content-media"><picture>', $html);
        self::assertStringContainsString('<source type="image/avif" srcset="/uploads/media/large.avif 1200w"', $html);
        self::assertStringContainsString('<img src="/uploads/media/large.jpg" alt="Vue detaillee du sentier"', $html);
        self::assertStringContainsString('width="1200" height="800"', $html);
        self::assertStringContainsString('<figcaption>Vue du sentier</figcaption>', $html);
        self::assertStringNotContainsString('[[media:77]]', $html);
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
