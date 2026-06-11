<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\Place;
use App\Entity\User;
use App\Enum\CommentStatus;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class CommentFixtures extends Fixture implements DependentFixtureInterface
{
    public const ARTICLE_APPROVED_REFERENCE = 'comment.article.approved';
    public const ARTICLE_APPROVED_REPLY_REFERENCE = 'comment.article.approved-reply';
    public const ARTICLE_PENDING_REFERENCE = 'comment.article.pending';
    public const ARTICLE_SPAM_REFERENCE = 'comment.article.spam';
    public const ARTICLE_DELETED_REFERENCE = 'comment.article.deleted';
    public const PLACE_FORT_APPROVED_REFERENCE = 'comment.place.fort-approved';
    public const PLACE_PLAGE_APPROVED_REFERENCE = 'comment.place.plage-approved';
    public const PLACE_LAC_PENDING_REFERENCE = 'comment.place.lac-pending';
    public const ARTICLE_POPULAR_REFERENCE = 'comment.article.popular';
    public const ARTICLE_PINNED_REFERENCE = 'comment.article.pinned';
    public const ARTICLE_ADMIN_HEART_REFERENCE = 'comment.article.admin-heart';
    public const ARTICLE_MENTION_REFERENCE = 'comment.article.mention';
    public const ARTICLE_LONG_REFERENCE = 'comment.article.long';
    public const ARTICLE_SECOND_REPLY_REFERENCE = 'comment.article.second-reply';
    public const PLACE_CERET_APPROVED_REFERENCE = 'comment.place.ceret-approved';
    public const PLACE_PAULILLES_APPROVED_REFERENCE = 'comment.place.paulilles-approved';

    public function load(ObjectManager $manager): void
    {
        $admin = $this->getUser(UserFixtures::ADMIN_REFERENCE);
        $user = $this->getUser(UserFixtures::USER_REFERENCE);
        $trusted = $this->getUser(UserFixtures::TRUSTED_REFERENCE);
        $noAvatar = $this->getUser(UserFixtures::NO_AVATAR_REFERENCE);

        $approvedArticleComment = (new Comment())
            ->setAuthor($trusted)
            ->setArticle($this->getArticle(ArticleFixtures::COLLIOURE_ONE_DAY_REFERENCE))
            ->setContent('Itinéraire testé hors saison : la montee au Fort Saint-Elme vaut vraiment l effort pour la vue.')
            ->setStatus(CommentStatus::Approved)
            ->setApprovedAt(new DateTimeImmutable('-7 days 10:00'))
            ->setPublishedAt(new DateTimeImmutable('-7 days 09:50'))
            ->setModeratedAt(new DateTimeImmutable('-7 days 10:00'))
            ->setModeratedBy($admin)
            ->setSpamScore(3)
            ->setIpAddress('203.0.113.10')
            ->setUserAgent('Mozilla/5.0 Fixture Browser');
        $manager->persist($approvedArticleComment);
        $this->addReference(self::ARTICLE_APPROVED_REFERENCE, $approvedArticleComment);

        $reply = (new Comment())
            ->setAuthor($admin)
            ->setArticle($this->getArticle(ArticleFixtures::COLLIOURE_ONE_DAY_REFERENCE))
            ->setParent($approvedArticleComment)
            ->setContent('Merci pour le retour ! Le matin reste effectivement le meilleur moment pour profiter du panorama.')
            ->setStatus(CommentStatus::Approved)
            ->setApprovedAt(new DateTimeImmutable('-6 days 18:00'))
            ->setPublishedAt(new DateTimeImmutable('-6 days 17:55'))
            ->setModeratedAt(new DateTimeImmutable('-6 days 18:00'))
            ->setModeratedBy($admin)
            ->setSpamScore(0)
            ->setIpAddress('203.0.113.11')
            ->setUserAgent('Mozilla/5.0 Fixture Browser');
        $manager->persist($reply);
        $this->addReference(self::ARTICLE_APPROVED_REPLY_REFERENCE, $reply);

        $pendingArticleComment = (new Comment())
            ->setAuthor($user)
            ->setArticle($this->getArticle(ArticleFixtures::COLLIOURE_ONE_DAY_REFERENCE))
            ->setContent('Est-ce que cet itineraire reste facile avec une poussette sur la partie vers le fort ?')
            ->setStatus(CommentStatus::Pending)
            ->setSpamScore(12)
            ->setReportedCount(1)
            ->setIpAddress('198.51.100.20')
            ->setUserAgent('Mozilla/5.0 Fixture Browser');
        $manager->persist($pendingArticleComment);
        $this->addReference(self::ARTICLE_PENDING_REFERENCE, $pendingArticleComment);

        $spamComment = (new Comment())
            ->setAuthor($user)
            ->setArticle($this->getArticle(ArticleFixtures::BEST_PO_REFERENCE))
            ->setContent('Casino crypto facile argent rapide cliquez ici pour gagner maintenant avec une offre douteuse.')
            ->setStatus(CommentStatus::Spam)
            ->setModerationReason('Score de spam eleve avec mots-cles promotionnels interdits.')
            ->setSpamScore(95)
            ->setReportedCount(1)
            ->setModeratedAt(new DateTimeImmutable('-5 days 13:00'))
            ->setModeratedBy($admin)
            ->setIpAddress('198.51.100.30')
            ->setUserAgent('Mozilla/5.0 Fixture Browser');
        $manager->persist($spamComment);
        $this->addReference(self::ARTICLE_SPAM_REFERENCE, $spamComment);

        $deletedComment = (new Comment())
            ->setAuthor($user)
            ->setArticle($this->getArticle(ArticleFixtures::FORT_SAINT_ELME_REFERENCE))
            ->setContent('Commentaire supprimé par son auteur.')
            ->setStatus(CommentStatus::Deleted)
            ->setModeratedAt(new DateTimeImmutable('-4 days 15:00'))
            ->setModeratedBy($admin)
            ->setEditedAt(new DateTimeImmutable('-4 days 15:00'))
            ->setIpAddress('198.51.100.40')
            ->setUserAgent('Mozilla/5.0 Fixture Browser');
        $manager->persist($deletedComment);
        $this->addReference(self::ARTICLE_DELETED_REFERENCE, $deletedComment);

        $fortComment = (new Comment())
            ->setAuthor($trusted)
            ->setPlace($this->getPlace(PlaceFixtures::FORT_SAINT_ELME_REFERENCE))
            ->setContent('Tres belle visite, surtout pour les points de vue sur Collioure et Port-Vendres.')
            ->setStatus(CommentStatus::Approved)
            ->setApprovedAt(new DateTimeImmutable('-3 days 11:00'))
            ->setPublishedAt(new DateTimeImmutable('-3 days 10:55'))
            ->setModeratedAt(new DateTimeImmutable('-3 days 11:00'))
            ->setModeratedBy($admin)
            ->setSpamScore(2)
            ->setIpAddress('203.0.113.21')
            ->setUserAgent('Mozilla/5.0 Fixture Browser');
        $manager->persist($fortComment);
        $this->addReference(self::PLACE_FORT_APPROVED_REFERENCE, $fortComment);

        $plageComment = (new Comment())
            ->setAuthor($trusted)
            ->setPlace($this->getPlace(PlaceFixtures::PLAGE_BORAMAR_REFERENCE))
            ->setContent('Parfait pour une pause en fin de journee, mais il faut arriver tot en ete.')
            ->setStatus(CommentStatus::Approved)
            ->setApprovedAt(new DateTimeImmutable('-2 days 17:00'))
            ->setPublishedAt(new DateTimeImmutable('-2 days 16:50'))
            ->setModeratedAt(new DateTimeImmutable('-2 days 17:00'))
            ->setModeratedBy($admin)
            ->setSpamScore(1)
            ->setIpAddress('203.0.113.22')
            ->setUserAgent('Mozilla/5.0 Fixture Browser');
        $manager->persist($plageComment);
        $this->addReference(self::PLACE_PLAGE_APPROVED_REFERENCE, $plageComment);

        $lacPendingComment = (new Comment())
            ->setAuthor($user)
            ->setPlace($this->getPlace(PlaceFixtures::LAC_BOUILLOUSES_REFERENCE))
            ->setContent('Les navettes sont-elles obligatoires en septembre pour acceder au lac ?')
            ->setStatus(CommentStatus::Pending)
            ->setSpamScore(8)
            ->setIpAddress('198.51.100.50')
            ->setUserAgent('Mozilla/5.0 Fixture Browser');
        $manager->persist($lacPendingComment);
        $this->addReference(self::PLACE_LAC_PENDING_REFERENCE, $lacPendingComment);

        $popularComment = $this->approvedComment(
            author: $user,
            article: $this->getArticle(ArticleFixtures::COLLIOURE_ONE_DAY_REFERENCE),
            content: 'Nous avons suivi ce parcours avec deux pauses et le timing etait parfait. Le passage par le port puis la montee progressive donne une bonne lecture de Collioure.',
            approvedAt: new DateTimeImmutable('-6 days 09:00'),
            admin: $admin,
        );
        $manager->persist($popularComment);
        $this->addReference(self::ARTICLE_POPULAR_REFERENCE, $popularComment);

        $pinnedComment = $this->approvedComment(
            author: $admin,
            article: $this->getArticle(ArticleFixtures::COLLIOURE_ONE_DAY_REFERENCE),
            content: 'Note admin : en ete, partez tot et prevoyez de l eau. Ce commentaire est epingle pour tester la mise en avant.',
            approvedAt: new DateTimeImmutable('-5 days 12:00'),
            admin: $admin,
        )
            ->setPinnedAt(new DateTimeImmutable('-5 days 12:10'))
            ->setPinnedBy($admin);
        $manager->persist($pinnedComment);
        $this->addReference(self::ARTICLE_PINNED_REFERENCE, $pinnedComment);

        $heartComment = $this->approvedComment(
            author: $trusted,
            article: $this->getArticle(ArticleFixtures::BEST_PO_REFERENCE),
            content: 'La selection donne envie de combiner mer et montagne sur trois jours, surtout avec le lac en fin de sejour.',
            approvedAt: new DateTimeImmutable('-5 days 15:00'),
            admin: $admin,
        )
            ->setAdminHeartedAt(new DateTimeImmutable('-5 days 15:15'))
            ->setAdminHeartedBy($admin);
        $manager->persist($heartComment);
        $this->addReference(self::ARTICLE_ADMIN_HEART_REFERENCE, $heartComment);

        $mentionComment = $this->approvedComment(
            author: $user,
            article: $this->getArticle(ArticleFixtures::MEDITERRANEAN_HIKE_REFERENCE),
            content: 'Merci @Randonneur Confiance pour les conseils sur l eau, c est exactement le point que je voulais verifier avant une sortie en mai.',
            approvedAt: new DateTimeImmutable('-4 days 09:00'),
            admin: $admin,
        );
        $manager->persist($mentionComment);
        $this->addReference(self::ARTICLE_MENTION_REFERENCE, $mentionComment);

        $longComment = $this->approvedComment(
            author: $noAvatar,
            article: $this->getArticle(ArticleFixtures::LONG_ARTICLE_REFERENCE),
            content: str_repeat('Retour tres detaille sur la cote Vermeille avec un accent sur les horaires, les pauses, les parkings et les alternatives sans voiture. ', 8),
            approvedAt: new DateTimeImmutable('-3 days 10:00'),
            admin: $admin,
        );
        $manager->persist($longComment);
        $this->addReference(self::ARTICLE_LONG_REFERENCE, $longComment);

        $secondReply = $this->approvedComment(
            author: $trusted,
            article: $this->getArticle(ArticleFixtures::COLLIOURE_ONE_DAY_REFERENCE),
            content: 'Je confirme, la poussette n est pas adaptee pour la montee vers le fort. Mieux vaut rester sur le front de mer avec de jeunes enfants.',
            approvedAt: new DateTimeImmutable('-2 days 11:00'),
            admin: $admin,
        )->setParent($approvedArticleComment);
        $manager->persist($secondReply);
        $this->addReference(self::ARTICLE_SECOND_REPLY_REFERENCE, $secondReply);

        $ceretComment = (new Comment())
            ->setAuthor($trusted)
            ->setPlace($this->getPlace(PlaceFixtures::MARCHE_CERET_REFERENCE))
            ->setContent('Le marche de Céret est un bon test pour les visites de ville et les lieux culturels hors littoral.')
            ->setStatus(CommentStatus::Approved)
            ->setApprovedAt(new DateTimeImmutable('-2 days 12:00'))
            ->setPublishedAt(new DateTimeImmutable('-2 days 11:55'))
            ->setModeratedAt(new DateTimeImmutable('-2 days 12:00'))
            ->setModeratedBy($admin)
            ->setSpamScore(1)
            ->setIpAddress('203.0.113.23')
            ->setUserAgent('Mozilla/5.0 Fixture Browser');
        $manager->persist($ceretComment);
        $this->addReference(self::PLACE_CERET_APPROVED_REFERENCE, $ceretComment);

        $paulillesComment = (new Comment())
            ->setAuthor($user)
            ->setPlace($this->getPlace(PlaceFixtures::PLAGE_PAULILLES_REFERENCE))
            ->setContent('Question pratique : le site reste-t-il agréable en semaine hors saison pour une pause après randonnée ?')
            ->setStatus(CommentStatus::Approved)
            ->setApprovedAt(new DateTimeImmutable('-1 day 12:00'))
            ->setPublishedAt(new DateTimeImmutable('-1 day 11:55'))
            ->setModeratedAt(new DateTimeImmutable('-1 day 12:00'))
            ->setModeratedBy($admin)
            ->setSpamScore(2)
            ->setIpAddress('203.0.113.24')
            ->setUserAgent('Mozilla/5.0 Fixture Browser');
        $manager->persist($paulillesComment);
        $this->addReference(self::PLACE_PAULILLES_APPROVED_REFERENCE, $paulillesComment);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            ArticleFixtures::class,
            PlaceFixtures::class,
        ];
    }

    private function getUser(string $reference): User
    {
        return $this->getReference($reference, User::class);
    }

    private function getArticle(string $reference): Article
    {
        return $this->getReference($reference, Article::class);
    }

    private function getPlace(string $reference): Place
    {
        return $this->getReference($reference, Place::class);
    }

    private function approvedComment(User $author, Article $article, string $content, DateTimeImmutable $approvedAt, User $admin): Comment
    {
        return (new Comment())
            ->setAuthor($author)
            ->setArticle($article)
            ->setContent($content)
            ->setStatus(CommentStatus::Approved)
            ->setApprovedAt($approvedAt)
            ->setPublishedAt($approvedAt->modify('-5 minutes'))
            ->setModeratedAt($approvedAt)
            ->setModeratedBy($admin)
            ->setSpamScore(0)
            ->setIpAddress('203.0.113.70')
            ->setUserAgent('Mozilla/5.0 Fixture Browser');
    }
}
