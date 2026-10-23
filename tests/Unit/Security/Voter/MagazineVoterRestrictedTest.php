<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\Magazine;
use App\Entity\MagazineMember;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Security\Voter\MagazineVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Someone who cannot view a restricted magazine can do nothing else with it either.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineVoterRestrictedTest extends TestCase
{
    /**
     * @return iterable<string, array{MagazineAccess, string}>
     */
    public static function refusedProvider(): iterable
    {
        foreach ([MagazineAccess::Private, MagazineAccess::Hidden] as $access) {
            foreach ([MagazineVoter::CREATE_CONTENT, MagazineVoter::SUBSCRIBE, MagazineVoter::BLOCK] as $attribute) {
                yield "$attribute on {$access->value}" => [$access, $attribute];
            }
        }
    }

    #[DataProvider('refusedProvider')]
    public function testNonViewerIsRefusedAndMemberIsNot(MagazineAccess $access, string $attribute): void
    {
        $magazine = new Magazine('circle', 'Circle', null, null, null, false, false, null);
        $magazine->access = $access;
        $stranger = new User('stranger@example.com', 'stranger', 'secret', User::USER_TYPE_PERSON);
        $member = new User('member@example.com', 'member', 'secret', User::USER_TYPE_PERSON);
        $magazine->members->add(new MagazineMember($magazine, $member, null));

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($stranger, $magazine, $attribute), 'non-viewer');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($member, $magazine, $attribute), 'positive control: member');
    }

    private function vote(User $user, Magazine $magazine, string $attribute): int
    {
        return (new MagazineVoter())->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $magazine, [$attribute]);
    }
}
