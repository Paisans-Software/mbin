<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\Magazine;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Repository\MagazineMemberRepository;
use App\Service\MagazineMemberManager;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineMemberManagerTest extends WebTestCase
{
    private function manager(): MagazineMemberManager
    {
        return static::getContainer()->get(MagazineMemberManager::class);
    }

    private function restrictedMagazine(): Magazine
    {
        $magazine = $this->getMagazineByName('secret', $this->getUserByUsername('owner'));
        $magazine->access = MagazineAccess::Private;
        $this->entityManager->flush();

        return $magazine;
    }

    public function testAddIsIdempotent(): void
    {
        $manager = $this->manager();
        $magazine = $this->restrictedMagazine();
        $user = $this->getUserByUsername('member');
        $admin = $this->getUserByUsername('admin', isAdmin: true);

        $first = $manager->add($magazine, $user, $admin);
        $second = $manager->add($magazine, $user, $admin);

        self::assertSame($first->getId(), $second->getId());
        self::assertCount(1, $magazine->members);
        self::assertTrue($magazine->isMember($user));
    }

    public function testAddRefusesRemoteUser(): void
    {
        $manager = $this->manager();
        $magazine = $this->restrictedMagazine();
        $remote = $this->getUserByUsername('remoteuser');
        $remote->apId = 'remoteuser@example.org';
        $this->entityManager->flush();

        $this->expectException(\InvalidArgumentException::class);
        $manager->add($magazine, $remote, $this->getUserByUsername('owner'));
    }

    public function testAddRefusesPublicMagazine(): void
    {
        $manager = $this->manager();
        $magazine = $this->getMagazineByName('open', $this->getUserByUsername('owner'));

        $this->expectException(\InvalidArgumentException::class);
        $manager->add($magazine, $this->getUserByUsername('member'), $this->getUserByUsername('owner'));
    }

    public function testRemoveAlsoUnsubscribes(): void
    {
        $manager = $this->manager();
        $magazine = $this->restrictedMagazine();
        $user = $this->getUserByUsername('member');

        $manager->add($magazine, $user, $this->getUserByUsername('owner'));
        $this->magazineManager->subscribe($magazine, $user);
        self::assertTrue($magazine->isSubscribed($user));

        $manager->remove($magazine, $user);

        self::assertFalse($magazine->isMember($user));
        self::assertFalse($magazine->isSubscribed($user));

        $this->entityManager->clear();
        $magazine = $this->magazineRepository->findOneByName('secret');
        $user = $this->userRepository->findOneByUsername('member');
        self::assertNull(static::getContainer()->get(MagazineMemberRepository::class)->findOneByMagazineAndUser($magazine, $user));
        self::assertFalse($magazine->isSubscribed($user));
    }

    public function testAddToHiddenMagazine(): void
    {
        $magazine = $this->restrictedMagazine();
        $magazine->access = MagazineAccess::Hidden;
        $this->entityManager->flush();
        $user = $this->getUserByUsername('member');

        $this->manager()->add($magazine, $user, $this->getUserByUsername('owner'));

        self::assertTrue($magazine->isMember($user));
        self::assertTrue($magazine->userCanView($user));
    }

    public function testRemoveOfNonMemberIsANoOp(): void
    {
        $magazine = $this->restrictedMagazine();
        $member = $this->getUserByUsername('member');
        $this->manager()->add($magazine, $member, $this->getUserByUsername('owner'));

        $this->manager()->remove($magazine, $this->getUserByUsername('stranger'));

        self::assertCount(1, $magazine->members);
        self::assertTrue($magazine->isMember($member));
    }

    /**
     * @return array<string, array{callable(User): void}>
     */
    public static function unusableAccountProvider(): array
    {
        return [
            'banned from the instance' => [static function (User $user): void { $user->isBanned = true; }],
            'deleted' => [static function (User $user): void { $user->isDeleted = true; }],
            'soft-deleted' => [static function (User $user): void { $user->softDelete(); }],
        ];
    }

    /**
     * @param callable(User): void $makeUnusable
     */
    #[DataProvider('unusableAccountProvider')]
    public function testAddRefusesUnusableAccount(callable $makeUnusable): void
    {
        $magazine = $this->restrictedMagazine();
        $user = $this->getUserByUsername('member');
        $makeUnusable($user);
        $this->entityManager->flush();

        try {
            $this->manager()->add($magazine, $user, $this->getUserByUsername('owner'));
            self::fail('expected the add to be refused');
        } catch (\InvalidArgumentException) {
        }
        self::assertFalse($magazine->isMember($user));
    }
}
