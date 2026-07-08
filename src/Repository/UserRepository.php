<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<User> */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function findOneByGoogleId(string $googleId): ?User
    {
        return $this->findOneBy(['googleId' => $googleId]);
    }

    /** @return list<User> */
    public function findUsersSubscribedToPublicationEmails(): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->andWhere('u.receivePublicationEmails = :enabled')
            ->andWhere('u.isVerified = :verified')
            ->andWhere('u.isBanned = :banned')
            ->setParameter('enabled', true)
            ->setParameter('verified', true)
            ->setParameter('banned', false)
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $users;
    }

    /**
     * @param list<string> $handles
     *
     * @return list<User>
     */
    public function findMentionableUsersByHandles(array $handles): array
    {
        $handles = array_values(array_unique(array_filter(array_map(
            static fn (string $handle): string => mb_strtolower(trim($handle, '@ ')),
            $handles,
        ))));

        if ($handles === []) {
            return [];
        }

        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->andWhere('u.isBanned = :banned')
            ->setParameter('banned', false)
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();

        $handleLookup = array_fill_keys($handles, true);
        $matchedUsers = [];

        foreach ($users as $user) {
            $mentionHandle = $user->getMentionHandle();
            if (isset($handleLookup[$mentionHandle]) && !isset($matchedUsers[$mentionHandle])) {
                $matchedUsers[$mentionHandle] = $user;
            }
        }

        return array_values($matchedUsers);
    }
}
