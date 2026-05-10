<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserFixtures extends Fixture
{
    public const ADMIN_REFERENCE = 'user.admin';
    public const USER_REFERENCE = 'user.normal';
    public const TRUSTED_REFERENCE = 'user.trusted';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $users = [
            self::ADMIN_REFERENCE => [
                'email' => 'admin@blog-tourisme.local',
                'roles' => ['ROLE_ADMIN', 'ROLE_USER'],
                'displayName' => 'Admin Blog Tourisme',
                'trustedCommenter' => true,
                'approvedCommentsCount' => 10,
            ],
            self::USER_REFERENCE => [
                'email' => 'user@blog-tourisme.local',
                'roles' => ['ROLE_USER'],
                'displayName' => 'Voyageur Test',
                'trustedCommenter' => false,
                'approvedCommentsCount' => 0,
            ],
            self::TRUSTED_REFERENCE => [
                'email' => 'trusted@blog-tourisme.local',
                'roles' => ['ROLE_USER'],
                'displayName' => 'Voyageur Confirmé',
                'trustedCommenter' => true,
                'approvedCommentsCount' => 5,
            ],
        ];

        foreach ($users as $reference => $data) {
            $user = (new User())
                ->setEmail($data['email'])
                ->setRoles($data['roles'])
                ->setDisplayName($data['displayName'])
                ->setTrustedCommenter($data['trustedCommenter'])
                ->setApprovedCommentsCount($data['approvedCommentsCount']);

            $user->setPassword($this->passwordHasher->hashPassword($user, 'password'));

            $manager->persist($user);
            $this->addReference($reference, $user);
        }

        $manager->flush();
    }
}
