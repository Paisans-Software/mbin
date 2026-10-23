<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\Magazine;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Repository\SearchRepository;
use App\Tests\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Looking a magazine up by its ActivityPub id is discovery: a hidden magazine is not
 * found except by admins and global moderators; a private one is.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class SearchFindByApIdAccessTest extends WebTestCase
{
    /**
     * @return list<string>
     */
    private function magazinesFoundBy(?User $viewer, Magazine $magazine): array
    {
        $tokenStorage = static::getContainer()->get('security.token_storage');
        $tokenStorage->setToken(null === $viewer ? null : new UsernamePasswordToken($viewer, 'main', $viewer->getRoles()));

        $found = static::getContainer()->get(SearchRepository::class)->findByApId($magazine->apProfileId);

        return array_map(fn (Magazine $m) => $m->name, array_values(array_filter($found, fn ($o) => $o instanceof Magazine)));
    }

    public function testHiddenMagazineIsFoundOnlyBySiteStaff(): void
    {
        $admin = $this->getUserByUsername('admin', isAdmin: true);
        $vault = $this->getMagazineByName('vault', $admin);
        $vault->access = MagazineAccess::Hidden;
        $circle = $this->getMagazineByName('circle', $admin);
        $circle->access = MagazineAccess::Private;
        $this->entityManager->flush();
        self::assertNotNull($vault->apProfileId);

        $bob = $this->getUserByUsername('bob');
        self::assertSame([], $this->magazinesFoundBy($bob, $vault), 'non-member');
        self::assertSame([], $this->magazinesFoundBy(null, $vault), 'anonymous');
        self::assertSame(['vault'], $this->magazinesFoundBy($admin, $vault), 'admin');
        self::assertSame(['vault'], $this->magazinesFoundBy($this->getUserByUsername('gmod', isModerator: true), $vault), 'global moderator');
        self::assertSame(['circle'], $this->magazinesFoundBy($bob, $circle), 'a private magazine stays discoverable');
    }
}
