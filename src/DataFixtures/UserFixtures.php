<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserFixtures extends Fixture implements FixtureGroupInterface
{
    public const ADMIN_REFERENCE = 'user.admin';
    public const USER_REFERENCE = 'user.normal';
    public const TRUSTED_REFERENCE = 'user.trusted';
    public const UNVERIFIED_REFERENCE = 'user.unverified';
    public const NO_AVATAR_REFERENCE = 'user.no-avatar';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly string $projectDir,
    ) {}

    public static function getGroups(): array
    {
        return ['user'];
    }

    public function load(ObjectManager $manager): void
    {
        $users = [
            self::ADMIN_REFERENCE => [
                'email' => 'admin-test@example.test',
                'password' => 'PasswordAdmin2026!',
                'roles' => ['ROLE_ADMIN', 'ROLE_USER'],
                'displayName' => 'Admin Blog Tourisme',
                'trustedCommenter' => true,
                'approvedCommentsCount' => 10,
                'isVerified' => true,
                'avatar' => 'fixture_avatar_admin',
            ],
            self::USER_REFERENCE => [
                'email' => 'user-test@example.test',
                'password' => 'PasswordUser2026!',
                'roles' => ['ROLE_USER'],
                'displayName' => 'Aurélien Test',
                'trustedCommenter' => false,
                'approvedCommentsCount' => 3,
                'isVerified' => true,
                'avatar' => 'fixture_avatar_user',
            ],
            self::TRUSTED_REFERENCE => [
                'email' => 'trusted@blog-tourisme.local',
                'password' => 'PasswordTrusted2026!',
                'roles' => ['ROLE_USER'],
                'displayName' => 'Randonneur Confiance',
                'trustedCommenter' => true,
                'approvedCommentsCount' => 25,
                'isVerified' => true,
                'avatar' => 'fixture_avatar_trusted',
            ],
            self::UNVERIFIED_REFERENCE => [
                'email' => 'unverified@blog-tourisme.local',
                'password' => 'PasswordUnverified2026!',
                'roles' => ['ROLE_USER'],
                'displayName' => 'Compte Non Vérifié',
                'trustedCommenter' => false,
                'approvedCommentsCount' => 0,
                'isVerified' => false,
                'avatar' => null,
            ],
            self::NO_AVATAR_REFERENCE => [
                'email' => 'noavatar@blog-tourisme.local',
                'password' => 'PasswordNoAvatar2026!',
                'roles' => ['ROLE_USER'],
                'displayName' => 'Sans Avatar',
                'trustedCommenter' => false,
                'approvedCommentsCount' => 1,
                'isVerified' => true,
                'avatar' => null,
            ],
        ];

        foreach ($users as $reference => $data) {
            $avatarPath = is_string($data['avatar']) ? $this->generateAvatar($data['avatar'], $data['displayName']) : null;

            $user = (new User())
                ->setEmail($data['email'])
                ->setRoles($data['roles'])
                ->setDisplayName($data['displayName'])
                ->setIsVerified($data['isVerified'])
                ->setTrustedCommenter($data['trustedCommenter'])
                ->setApprovedCommentsCount($data['approvedCommentsCount'])
                ->setAvatarPath($avatarPath);

            $user->setPassword($this->passwordHasher->hashPassword($user, $data['password']));

            $manager->persist($user);
            $this->addReference($reference, $user);
        }

        $manager->flush();
    }

    private function generateAvatar(string $basename, string $label): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $directory = $this->projectDir.'/public/uploads/avatars';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $supportsWebp = function_exists('imagewebp');
        $extension = $supportsWebp ? 'webp' : 'jpg';
        $relativePath = sprintf('/uploads/avatars/%s.%s', $basename, $extension);
        $absolutePath = $this->projectDir.'/public'.$relativePath;

        $image = imagecreatetruecolor(256, 256);
        if ($image === false) {
            return null;
        }

        $palettes = [
            'fixture_avatar_admin' => [[24, 68, 92], [255, 214, 102]],
            'fixture_avatar_user' => [[34, 111, 84], [238, 246, 239]],
            'fixture_avatar_trusted' => [[112, 72, 36], [252, 230, 179]],
        ];
        [$backgroundRgb, $foregroundRgb] = $palettes[$basename] ?? [[70, 70, 70], [245, 245, 245]];

        $background = imagecolorallocate($image, ...$backgroundRgb);
        $foreground = imagecolorallocate($image, ...$foregroundRgb);
        $accent = imagecolorallocate($image, 255, 255, 255);
        if ($background === false || $foreground === false || $accent === false) {
            imagedestroy($image);

            return null;
        }

        imagefilledrectangle($image, 0, 0, 255, 255, $background);
        imagefilledellipse($image, 178, 68, 140, 140, $foreground);
        imagefilledellipse($image, 74, 190, 180, 180, $foreground);
        imagefilledrectangle($image, 0, 132, 255, 255, $background);

        $initials = $this->initials($label);
        imagestring($image, 5, 104, 116, $initials, $accent);

        $written = $supportsWebp ? imagewebp($image, $absolutePath, 86) : imagejpeg($image, $absolutePath, 88);
        imagedestroy($image);

        return $written ? $relativePath : null;
    }

    private function initials(string $label): string
    {
        $words = preg_split('/\s+/', trim($label)) ?: [];
        $letters = '';
        foreach ($words as $word) {
            $letters .= mb_substr($word, 0, 1);
            if (mb_strlen($letters) >= 2) {
                break;
            }
        }

        return mb_strtoupper($letters !== '' ? $letters : '?');
    }
}
