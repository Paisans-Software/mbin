<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\Magazine;
use App\Entity\MagazineMember;
use App\Enum\MagazineAccess;
use App\Repository\MagazineMemberRepository;
use App\Service\MagazineMemberManager;
use App\Tests\WebTestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineMemberManagerTest extends WebTestCase
{
    private function manager(): MagazineMemberManager
    {
        /** @var MagazineMemberRepository $memberRepository */
        $memberRepository = $this->entityManager->getRepository(MagazineMember::class);

        return new MagazineMemberManager(
            $memberRepository,
            $this->magazineManager,
            $this->entityManager,
        );
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
    }
}
