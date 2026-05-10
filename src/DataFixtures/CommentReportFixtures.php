<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Comment;
use App\Entity\CommentReport;
use App\Entity\User;
use App\Enum\CommentReportReason;
use App\Enum\CommentReportStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class CommentReportFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $trusted = $this->getUser(UserFixtures::TRUSTED_REFERENCE);
        $admin = $this->getUser(UserFixtures::ADMIN_REFERENCE);

        $spamReport = (new CommentReport())
            ->setComment($this->getComment(CommentFixtures::ARTICLE_SPAM_REFERENCE))
            ->setReporter($trusted)
            ->setReason(CommentReportReason::Spam)
            ->setMessage('Message promotionnel manifestement automatise.')
            ->setStatus(CommentReportStatus::Pending)
            ->setIpAddress('203.0.113.60')
            ->setUserAgent('Mozilla/5.0 Fixture Browser');
        $manager->persist($spamReport);

        $inappropriateReport = (new CommentReport())
            ->setComment($this->getComment(CommentFixtures::ARTICLE_PENDING_REFERENCE))
            ->setReporter($admin)
            ->setReason(CommentReportReason::Inappropriate)
            ->setMessage('Signalement de demonstration pour tester le traitement manuel.')
            ->setStatus(CommentReportStatus::Pending)
            ->setIpAddress('203.0.113.61')
            ->setUserAgent('Mozilla/5.0 Fixture Browser');
        $manager->persist($inappropriateReport);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            CommentFixtures::class,
        ];
    }

    private function getUser(string $reference): User
    {
        return $this->getReference($reference, User::class);
    }

    private function getComment(string $reference): Comment
    {
        return $this->getReference($reference, Comment::class);
    }
}
