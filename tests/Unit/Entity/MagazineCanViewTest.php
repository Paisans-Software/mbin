<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Magazine;
use App\Entity\MagazineMember;
use App\Entity\Moderator;
use App\Entity\User;
use App\Enum\MagazineAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineCanViewTest extends TestCase
{
    private function user(string $name): User
    {
        return new User($name.'@example.com', $name, 'secret', User::USER_TYPE_PERSON);
    }

    private function magazine(MagazineAccess $access = MagazineAccess::Private, ?User $owner = null): Magazine
    {
        $magazine = new Magazine('mag', 'Mag', $owner, null, null, false, false, null);
        $magazine->access = $access;

        return $magazine;
    }

    private function addMember(Magazine $magazine, User $user): void
    {
        $magazine->members->add(new MagazineMember($magazine, $user, null));
    }

    public function testPublicMagazineVisibleToAnonymous(): void
    {
        $magazine = $this->magazine(MagazineAccess::Public);

        self::assertTrue($magazine->userCanView(null));
        self::assertTrue($magazine->userCanView($this->user('anyone')));
    }

    /**
     * @return iterable<string, array{MagazineAccess}>
     */
    public static function restrictedProvider(): iterable
    {
        yield 'private' => [MagazineAccess::Private];
        yield 'hidden' => [MagazineAccess::Hidden];
    }

    #[DataProvider('restrictedProvider')]
    public function testRestrictedHiddenFromAnonymousAndNonMember(MagazineAccess $access): void
    {
        $magazine = $this->magazine($access);

        self::assertFalse($magazine->userCanView(null));
        self::assertFalse($magazine->userCanView($this->user('outsider')));
    }

    public function testMemberCanView(): void
    {
        $magazine = $this->magazine();
        $member = $this->user('member');
        self::assertFalse($magazine->isMember($member));

        $this->addMember($magazine, $member);

        self::assertTrue($magazine->isMember($member));
        self::assertTrue($magazine->userCanView($member));
    }

    public function testBannedMemberCannotView(): void
    {
        $magazine = $this->magazine();
        $member = $this->user('member');
        $banner = $this->user('banner');
        $this->addMember($magazine, $member);

        $magazine->addBan($member, $banner, null, null);
        self::assertFalse($magazine->userCanView($member));

        $magazine->bans->clear();
        $magazine->addBan($member, $banner, null, new \DateTimeImmutable('-1 day'));
        self::assertTrue($magazine->userCanView($member));
    }

    public function testAdminAndGlobalModeratorCanView(): void
    {
        $magazine = $this->magazine();
        $admin = $this->user('admin');
        $admin->roles = ['ROLE_ADMIN'];
        $globalMod = $this->user('globalmod');
        $globalMod->roles = ['ROLE_MODERATOR'];

        self::assertTrue($magazine->userCanView($admin));
        self::assertTrue($magazine->userCanView($globalMod));
    }

    public function testMagazineModeratorCanView(): void
    {
        $owner = $this->user('owner');
        $magazine = $this->magazine(MagazineAccess::Private, $owner);
        $mod = $this->user('mod');
        $magazine->addModerator(new Moderator($magazine, $mod, null, false, true));

        self::assertTrue($magazine->userCanView($owner));
        self::assertTrue($magazine->userCanView($mod));
    }

    public function testRemoteMagazineIsNeverRestricted(): void
    {
        $magazine = $this->magazine(MagazineAccess::Private);
        $magazine->apId = 'remote@example.org';

        self::assertFalse($magazine->isRestricted());
        self::assertTrue($magazine->userCanView(null));
    }
}
