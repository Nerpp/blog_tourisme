<?php

namespace App\Tests\Functional;

use App\Contract\CommentableContentInterface;
use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\CommentReport;
use App\Entity\User;
use App\Enum\CommentReportReason;
use App\Enum\CommentStatus;
use App\Enum\ContentStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class CommentableContentControllerTest extends FunctionalTestCase
{
    public function testHikeUsesTheSharedSectionForCreationRepliesCountersAndIsolation(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createPublishedHike($admin);
        $article = $this->createArticle();
        $articleOnlyBody = 'Commentaire visible uniquement sur article '.$this->uniqueToken('article-only');
        $hikeRootBody = 'Premier commentaire de randonnée '.$this->uniqueToken('hike-root');
        $hiddenBody = 'Commentaire randonnée masqué '.$this->uniqueToken('hike-hidden');
        $createdBody = 'Nouveau commentaire centralisé sur randonnée '.$this->uniqueToken('hike-created');
        $replyBody = 'Réponse centralisée sur la randonnée '.$this->uniqueToken('hike-reply');

        $this->createComment($this->createUser(), $article, body: $articleOnlyBody);
        $root = $this->createComment($this->createUser(), $hike, body: $hikeRootBody);
        $this->createComment($this->createUser(), $hike, CommentStatus::HiddenByAdmin, $hiddenBody);
        $this->createCommentReply($this->createUser(), $root, CommentStatus::Rejected, 'Réponse randonnée rejetée et invisible.');

        $author = $this->createUser();
        $client->loginUser($author);
        $pagePath = sprintf('/randonnees/%s', $hike->getSlug());
        $createPath = sprintf('/comments/hike/%s', $hike->getSlug());
        $crawler = $client->request('GET', $pagePath);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.comments-section'));
        self::assertSame($createPath, $crawler->filter('#comment-form form')->attr('action'));
        self::assertSelectorTextContains('.comments-count', '1 commentaire');
        self::assertStringContainsString($hikeRootBody, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($articleOnlyBody, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($hiddenBody, (string) $client->getResponse()->getContent());

        $created = $this->postRootComment($client, $hike, $pagePath, $createPath, $createdBody, $author);
        self::assertStringStartsWith($pagePath.'#comment-', $client->getResponse()->headers->get('Location') ?? '');

        $replyAuthor = $this->createUser();
        $client->loginUser($replyAuthor);
        $reply = $this->postReply($client, $pagePath, $created, $replyAuthor, $replyBody);
        self::assertResponseRedirects(sprintf('%s#comment-%d', $pagePath, $reply->getId()));
        self::assertSame($hike->getCommentThread()->getId(), $reply->getThread()?->getId());

        $client->request('GET', $pagePath);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.comments-count', '3 commentaires');
        self::assertStringContainsString($createdBody, (string) $client->getResponse()->getContent());
        self::assertStringContainsString($replyBody, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($articleOnlyBody, (string) $client->getResponse()->getContent());
    }

    public function testCityVisitUsesTheSharedSectionForCreationRepliesCountersAndIsolation(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createPublishedCityVisit($admin);
        $hike = $this->createPublishedHike($admin);
        $hikeOnlyBody = 'Commentaire visible uniquement sur randonnée '.$this->uniqueToken('hike-only');
        $cityRootBody = 'Premier commentaire de visite '.$this->uniqueToken('city-root');
        $createdBody = 'Nouveau commentaire centralisé sur visite '.$this->uniqueToken('city-created');
        $replyBody = 'Réponse centralisée sur la visite '.$this->uniqueToken('city-reply');

        $this->createComment($this->createUser(), $hike, body: $hikeOnlyBody);
        $this->createComment($this->createUser(), $cityVisit, body: $cityRootBody);

        $author = $this->createUser();
        $client->loginUser($author);
        $pagePath = sprintf('/visites-de-ville/%s', $cityVisit->getSlug());
        $createPath = sprintf('/comments/city-visit/%s', $cityVisit->getSlug());
        $crawler = $client->request('GET', $pagePath);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.comments-section'));
        self::assertSame($createPath, $crawler->filter('#comment-form form')->attr('action'));
        self::assertSelectorTextContains('.comments-count', '1 commentaire');
        self::assertStringNotContainsString($hikeOnlyBody, (string) $client->getResponse()->getContent());

        $created = $this->postRootComment($client, $cityVisit, $pagePath, $createPath, $createdBody, $author);
        self::assertStringStartsWith($pagePath.'#comment-', $client->getResponse()->headers->get('Location') ?? '');

        $replyAuthor = $this->createUser();
        $client->loginUser($replyAuthor);
        $reply = $this->postReply($client, $pagePath, $created, $replyAuthor, $replyBody);
        self::assertResponseRedirects(sprintf('%s#comment-%d', $pagePath, $reply->getId()));
        self::assertSame($cityVisit->getCommentThread()->getId(), $reply->getThread()?->getId());

        $client->request('GET', $pagePath);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.comments-count', '3 commentaires');
        self::assertStringContainsString($cityRootBody, (string) $client->getResponse()->getContent());
        self::assertStringContainsString($createdBody, (string) $client->getResponse()->getContent());
        self::assertStringContainsString($replyBody, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($hikeOnlyBody, (string) $client->getResponse()->getContent());
    }

    public function testLikesAndReportsStayScopedToTheirCommentAcrossContentTypes(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createPublishedHike($admin);
        $cityVisit = $this->createPublishedCityVisit($admin);
        $hikeComment = $this->createComment($this->createUser(), $hike, body: 'Commentaire randonnée à aimer.');
        $cityComment = $this->createComment($this->createUser(), $cityVisit, body: 'Commentaire visite à signaler.');
        $actor = $this->createUser();
        $client->loginUser($actor);

        $hikeCrawler = $client->request('GET', sprintf('/randonnees/%s', $hike->getSlug()));
        $client->request('POST', sprintf('/comments/%d/like', $hikeComment->getId()), [
            '_token' => $this->tokenFromFormAction($hikeCrawler, sprintf('/comments/%d/like', $hikeComment->getId())),
        ]);
        self::assertResponseRedirects(sprintf('/randonnees/%s#comment-%d', $hike->getSlug(), $hikeComment->getId()));

        $cityCrawler = $client->request('GET', sprintf('/visites-de-ville/%s', $cityVisit->getSlug()));
        $client->request('POST', sprintf('/comments/%d/report', $cityComment->getId()), [
            '_token' => $this->tokenFromFormAction($cityCrawler, sprintf('/comments/%d/report', $cityComment->getId())),
            'reason' => CommentReportReason::Other->value,
            'message' => 'Signalement fonctionnel sur la bonne visite.',
        ]);
        self::assertResponseRedirects(sprintf('/visites-de-ville/%s#comments', $cityVisit->getSlug()));

        self::assertSame(1, $this->entityManager()->getRepository(CommentLike::class)->count([
            'comment' => $hikeComment,
            'user' => $actor,
        ]));
        self::assertSame(0, $this->entityManager()->getRepository(CommentLike::class)->count([
            'comment' => $cityComment,
            'user' => $actor,
        ]));
        self::assertSame(1, $this->entityManager()->getRepository(CommentReport::class)->count([
            'comment' => $cityComment,
            'reporter' => $actor,
        ]));
        self::assertSame(0, $this->entityManager()->getRepository(CommentReport::class)->count([
            'comment' => $hikeComment,
            'reporter' => $actor,
        ]));
    }

    public function testDraftPreviewAndGenericCreationCannotExposeNonPublicContent(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draftHike = $this->createHikeDraft($admin);
        $draftCityVisit = $this->createCityVisitDraft($admin);
        $draftArticle = $this->createArticle()->setStatus(ContentStatus::Draft);
        $this->persistAndFlush($draftArticle);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/randonnees/%s', $draftHike->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.comments-section');

        $client->request('GET', sprintf('/visites-de-ville/%s', $draftCityVisit->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.comments-section');

        foreach ([
            sprintf('/comments/hike/%s', $draftHike->getSlug()),
            sprintf('/comments/city-visit/%s', $draftCityVisit->getSlug()),
            sprintf('/comments/article/%s', $draftArticle->getSlug()),
            '/comments/hike/contenu-inexistant',
            '/comments/type-inconnu/contenu-inexistant',
        ] as $path) {
            $client->request('POST', $path, ['comment' => ['content' => 'Commentaire qui doit être refusé.']]);
            self::assertResponseStatusCodeSame(404, sprintf('La cible %s doit rester inaccessible.', $path));
        }
    }

    public function testAnonymousAndInvalidCsrfCannotCreateHikeComment(): void
    {
        $client = static::createClient();
        $hike = $this->createPublishedHike($this->createVerifiedAdmin());
        $path = sprintf('/comments/hike/%s', $hike->getSlug());

        $client->request('POST', $path, ['comment' => ['content' => 'Commentaire anonyme refusé.']]);
        self::assertResponseRedirects('/login');

        $author = $this->createUser();
        $client->loginUser($author);
        $client->request('POST', $path, [
            'comment' => [
                'content' => 'Commentaire avec un jeton CSRF invalide.',
                '_token' => 'bad-token',
            ],
        ]);

        self::assertResponseRedirects(sprintf('/randonnees/%s#comment-form', $hike->getSlug()));
        self::assertSame(0, $this->entityManager()->getRepository(Comment::class)->count([
            'thread' => $hike->getCommentThread(),
            'author' => $author,
        ]));
    }

    public function testCommentActionsBecomeUnavailableWhenTheirContentIsNoLongerPublic(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createPublishedHike($admin);
        $comment = $this->createComment($this->createUser(), $hike);
        $actor = $this->createUser();
        $client->loginUser($actor);
        $token = $this->csrfTokenForClient($client, 'like-comment-'.$comment->getId());
        $hike->setStatus(\App\Enum\HikeDraftStatus::Draft);
        $this->persistAndFlush($hike);

        $client->request('POST', sprintf('/comments/%d/like', $comment->getId()), ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->entityManager()->getRepository(CommentLike::class)->count([
            'comment' => $comment,
            'user' => $actor,
        ]));
    }

    private function postRootComment(
        KernelBrowser $client,
        CommentableContentInterface $content,
        string $pagePath,
        string $createPath,
        string $body,
        User $author,
    ): Comment {
        $crawler = $client->request('GET', $pagePath);
        self::assertResponseIsSuccessful();
        $client->request('POST', $createPath, [
            'comment' => [
                'content' => $body,
                '_token' => $this->inputValue($crawler, 'input[name="comment[_token]"]'),
            ],
        ]);
        self::assertResponseRedirects();

        $comment = $this->entityManager()->getRepository(Comment::class)->findOneBy([
            'thread' => $content->getCommentThread(),
            'author' => $author,
            'content' => $body,
        ]);
        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame(CommentStatus::Approved, $comment->getStatus());

        return $comment;
    }

    private function postReply(
        KernelBrowser $client,
        string $pagePath,
        Comment $parent,
        User $author,
        string $body,
    ): Comment {
        $crawler = $client->request('GET', $pagePath);
        self::assertResponseIsSuccessful();
        $replyPath = sprintf('/comments/%d/reply', $parent->getId());
        $client->request('POST', $replyPath, [
            '_token' => $this->tokenFromFormAction($crawler, $replyPath),
            'content' => $body,
            'website' => '',
        ]);
        self::assertResponseRedirects();

        $reply = $this->entityManager()->getRepository(Comment::class)->findOneBy([
            'parent' => $parent,
            'author' => $author,
            'content' => $body,
        ]);
        self::assertInstanceOf(Comment::class, $reply);
        self::assertSame(CommentStatus::Approved, $reply->getStatus());

        return $reply;
    }
}
