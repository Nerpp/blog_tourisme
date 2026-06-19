<?php

namespace App\Tests\Unit\Security;

use App\Entity\CityVisitPoint;
use App\Entity\HikePoint;
use App\Entity\User;
use App\Security\Voter\GpsAccessVoter;
use App\Security\Voter\QuickCityVisitVoter;
use App\Security\Voter\QuickHikeVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class QuickFieldToolVoterTest extends TestCase
{
    /**
     * @param class-string<QuickHikeVoter|QuickCityVisitVoter> $voterClass
     */
    #[DataProvider('quickCreateVoters')]
    public function testVerifiedAdminCanCreateQuickDraft(string $voterClass, string $attribute): void
    {
        $vote = (new $voterClass())->vote($this->tokenFor($this->user(['ROLE_ADMIN'], true)), null, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $vote);
    }

    /**
     * @param class-string<QuickHikeVoter|QuickCityVisitVoter> $voterClass
     */
    #[DataProvider('quickCreateVoters')]
    public function testQuickDraftCreationRequiresVerifiedAdmin(string $voterClass, string $attribute): void
    {
        $voter = new $voterClass();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->tokenFor($this->user(['ROLE_ADMIN'], false)), null, [$attribute]),
        );
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->tokenFor($this->user(['ROLE_USER'], true)), null, [$attribute]),
        );
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->tokenFor(null), null, [$attribute]),
        );
    }

    /**
     * @param class-string<QuickHikeVoter|QuickCityVisitVoter> $voterClass
     */
    #[DataProvider('quickCreateVoters')]
    public function testQuickDraftVotersAbstainOnUnsupportedAttribute(string $voterClass, string $_attribute): void
    {
        $vote = (new $voterClass())->vote($this->tokenFor($this->user(['ROLE_ADMIN'], true)), null, ['UNRELATED']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $vote);
    }

    /**
     * @return iterable<string, array{class-string<QuickHikeVoter|QuickCityVisitVoter>, string}>
     */
    public static function quickCreateVoters(): iterable
    {
        yield 'quick hike' => [QuickHikeVoter::class, QuickHikeVoter::CREATE];
        yield 'quick city visit' => [QuickCityVisitVoter::class, QuickCityVisitVoter::CREATE];
    }

    #[DataProvider('gpsSubjects')]
    public function testLoggedInUserCanOpenGpsPoint(object $subject): void
    {
        $vote = (new GpsAccessVoter())->vote($this->tokenFor($this->user(['ROLE_USER'], true)), $subject, [GpsAccessVoter::GPS_ACCESS]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $vote);
    }

    #[DataProvider('gpsSubjects')]
    public function testAnonymousUserCannotOpenGpsPoint(object $subject): void
    {
        $vote = (new GpsAccessVoter())->vote($this->tokenFor(null), $subject, [GpsAccessVoter::GPS_ACCESS]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $vote);
    }

    public function testGpsAccessVoterAbstainsOnUnsupportedSubjectOrAttribute(): void
    {
        $voter = new GpsAccessVoter();
        $token = $this->tokenFor($this->user(['ROLE_USER'], true));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new \stdClass(), [GpsAccessVoter::GPS_ACCESS]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new HikePoint(), ['UNRELATED']));
    }

    /** @return iterable<string, array{object}> */
    public static function gpsSubjects(): iterable
    {
        yield 'hike point' => [new HikePoint()];
        yield 'city visit point' => [new CityVisitPoint()];
    }

    /**
     * @param list<string> $roles
     */
    private function user(array $roles, bool $verified): User
    {
        return (new User())
            ->setEmail(strtolower(str_replace('ROLE_', '', $roles[0])).'-quick-voter@example.test')
            ->setDisplayName('Utilisateur quick voter')
            ->setPassword('test-password')
            ->setRoles($roles)
            ->setIsVerified($verified);
    }

    private function tokenFor(?User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
