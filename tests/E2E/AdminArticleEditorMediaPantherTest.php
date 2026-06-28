<?php

namespace App\Tests\E2E;

use App\Entity\Article;
use App\Entity\ArticleMedia;
use App\Entity\MediaAsset;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverWait;

final class AdminArticleEditorMediaPantherTest extends PantherTestCase
{
    public function testExistingImageCanBeInsertedAtTheEditorCursorAndSynchronized(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $context = $this->createArticleContext();
        $client = self::createBrowser();
        $this->loginAsAdmin($client, $context['email'], $context['password']);
        $client->request('GET', sprintf('/admin/articles/%d/edit', $context['article_id']));
        $client->waitFor('[data-article-editor]');

        $driver = $client->getWebDriver();
        $editor = $driver->findElement(WebDriverBy::cssSelector('[data-article-editor]'));
        $source = $driver->findElement(WebDriverBy::cssSelector('[data-article-editor-source]'));
        $insertButton = $driver->findElement(WebDriverBy::cssSelector('[data-article-insert-media]'));
        $mediaToken = (string) $insertButton->getAttribute('data-article-insert-media');

        self::assertMatchesRegularExpression('/^\[\[media:\d+\]\]$/', $mediaToken);
        self::assertSame('Insérer', trim((string) $insertButton->getText()));

        $driver->executeScript(<<<'JS'
            const editor = arguments[0];
            editor.innerHTML = '<p>Avant insertion.</p><p><br></p><p>Après insertion.</p>';
            const target = editor.querySelectorAll('p')[1];
            const range = document.createRange();
            range.selectNodeContents(target);
            range.collapse(true);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            editor.dispatchEvent(new Event('input', { bubbles: true }));
            editor.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
        JS, [$editor]);

        $driver->executeScript('arguments[0].click();', [$insertButton]);
        (new WebDriverWait($driver, 5))->until(
            static fn (): bool => str_contains((string) $source->getAttribute('value'), $mediaToken),
        );

        $sourceValue = (string) $source->getAttribute('value');
        self::assertStringContainsString('<p>Avant insertion.</p>', $sourceValue);
        self::assertMatchesRegularExpression(
            sprintf('#<p>(?:\s|&nbsp;)*%s(?:\s|&nbsp;)*</p>#', preg_quote($mediaToken, '#')),
            $sourceValue,
        );
        self::assertStringContainsString('<p>Après insertion.</p>', $sourceValue);
        self::assertStringContainsString(
            $mediaToken,
            (string) $driver->executeScript('return arguments[0].innerHTML;', [$editor]),
        );
        $this->assertNoBrowserSevereErrors($client);
    }

    /** @return array{email: string, password: string, article_id: int} */
    private function createArticleContext(): array
    {
        $email = $this->uniqueEmail('article-editor-media');
        $password = 'ArticleEditor123!';
        $user = $this->createVerifiedUser($email, $password, ['ROLE_ADMIN']);
        $userId = $user->getId();
        self::assertIsInt($userId);

        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $managedUser = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $managedUser);
        $token = bin2hex(random_bytes(6));
        $article = (new Article())
            ->setTitle('Article insertion média '.$token)
            ->setSlug('article-insertion-media-'.$token)
            ->setContent('<p>Contenu initial.</p>')
            ->setStatus(ContentStatus::Draft)
            ->setAuthor($managedUser);
        $media = (new MediaAsset())
            ->setTitle('Image à insérer')
            ->setAltText('Image de démonstration pour l’éditeur')
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/images/placeholders/destination-card-placeholder.webp')
            ->setThumbnailPath('/images/placeholders/destination-card-placeholder.webp')
            ->setWidth(640)
            ->setHeight(360)
            ->setVariants([
                'thumb' => [
                    'webp' => '/images/placeholders/destination-card-placeholder.webp',
                    'width' => 640,
                    'height' => 360,
                ],
            ]);
        $link = (new ArticleMedia())
            ->setArticle($article)
            ->setMediaAsset($media)
            ->setRole(MediaRole::Gallery)
            ->setPosition(0);
        $article->getMediaLinks()->add($link);
        $media->getArticleLinks()->add($link);
        $entityManager->persist($article);
        $entityManager->persist($media);
        $entityManager->persist($link);
        $entityManager->flush();
        $articleId = $article->getId();
        self::assertIsInt($articleId);
        self::ensureKernelShutdown();

        return ['email' => $email, 'password' => $password, 'article_id' => $articleId];
    }

    private function loginAsAdmin(\Symfony\Component\Panther\Client $client, string $email, string $password): void
    {
        $client->request('GET', '/login');
        $driver = $client->getWebDriver();
        $driver->findElement(WebDriverBy::name('_username'))->sendKeys($email);
        $driver->findElement(WebDriverBy::name('_password'))->sendKeys($password);
        $driver->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();
        $client->waitFor('.logout-form');
    }

    private function skipIfFrontendBuildIsMissing(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/public/build/manifest.json')) {
            self::markTestSkipped('Run docker compose run --rm node npm run build before this Panther test.');
        }
    }
}
