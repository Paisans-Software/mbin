<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Magazine\Panel;

use App\Entity\Magazine;
use App\Entity\MagazineMember;
use App\Enum\MagazineAccess;
use App\Service\MagazineMemberManager;
use App\Tests\WebTestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineMemberControllerTest extends WebTestCase
{
    private Magazine $circle;

    public function setUp(): void
    {
        parent::setUp();

        $owner = $this->getUserByUsername('owner');
        $this->circle = $this->getMagazineByName('circle', $owner);
        $this->circle->access = MagazineAccess::Private;
        $this->entityManager->flush();

        $this->getUserByUsername('alice');
        $this->getUserByUsername('bob');
    }

    private function memberManager(): MagazineMemberManager
    {
        return new MagazineMemberManager(
            $this->entityManager->getRepository(MagazineMember::class),
            $this->magazineManager,
            $this->entityManager,
        );
    }

    public function testModeratorAddsMemberByUsername(): void
    {
        $this->client->loginUser($this->getUserByUsername('owner'));

        $crawler = $this->client->request('GET', '/m/circle/panel/members');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#main .options__main a.active', 'Members');

        $this->client->submit(
            $crawler->filter('#main form[name=magazine_member]')->selectButton('Add member')->form([
                'magazine_member[username]' => 'alice',
            ])
        );
        $this->client->followRedirect();

        self::assertSelectorTextContains('#main .members-table', 'alice');
        $this->entityManager->clear();
        $circle = $this->entityManager->getRepository(Magazine::class)->findOneBy(['name' => 'circle']);
        self::assertTrue($circle->userCanView($this->getUserByUsername('alice')));
    }

    public function testModeratorRemovesMember(): void
    {
        $this->memberManager()->add($this->circle, $this->getUserByUsername('alice'), $this->getUserByUsername('owner'));

        $this->client->loginUser($this->getUserByUsername('owner'));
        $crawler = $this->client->request('GET', '/m/circle/panel/members');
        self::assertSelectorTextContains('#main .members-table', 'alice');

        $this->client->submit($crawler->filter('#main .members-table')->selectButton('Remove')->form());
        $this->client->followRedirect();
        self::assertSelectorTextNotContains('#main', 'alice');

        $this->client->loginUser($this->getUserByUsername('alice'));
        $this->client->request('GET', '/m/circle');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-magazine-stub]');
    }

    public function testRemoteUsernameRefused(): void
    {
        $remote = $this->getUserByUsername('remote_rita');
        $remote->apId = 'https://remote.example/u/rita';
        $this->entityManager->flush();

        $this->client->loginUser($this->getUserByUsername('owner'));
        $crawler = $this->client->request('GET', '/m/circle/panel/members');

        foreach (['remote_rita', 'nobody_here'] as $name) {
            $crawler = $this->client->submit(
                $crawler->filter('#main form[name=magazine_member]')->selectButton('Add member')->form([
                    'magazine_member[username]' => $name,
                ])
            );
            self::assertSelectorTextContains('#main', 'Only people with an account on this instance can be added.');
        }

        $this->entityManager->clear();
        $circle = $this->entityManager->getRepository(Magazine::class)->findOneBy(['name' => 'circle']);
        self::assertCount(0, $circle->members);
    }

    public function testNonModeratorGets404OrDenied(): void
    {
        $this->memberManager()->add($this->circle, $this->getUserByUsername('alice'), $this->getUserByUsername('owner'));

        $this->client->loginUser($this->getUserByUsername('bob'));
        $this->client->request('GET', '/m/circle/panel/members');
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->getUserByUsername('alice'));
        $this->client->request('GET', '/m/circle/panel/members');
        self::assertResponseStatusCodeSame(403);
    }

    public function testPanelTabAbsentForPublicMagazine(): void
    {
        $this->circle->access = MagazineAccess::Public;
        $this->entityManager->flush();

        $this->client->loginUser($this->getUserByUsername('owner'));
        $this->client->request('GET', '/m/circle/panel/general');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#options a[href="/m/circle/panel/members"]');

        $this->client->request('GET', '/m/circle/panel/members');
        self::assertResponseStatusCodeSame(404);

        $circle = $this->entityManager->getRepository(Magazine::class)->findOneBy(['name' => 'circle']);
        $circle->access = MagazineAccess::Private;
        $this->entityManager->flush();
        $this->client->request('GET', '/m/circle/panel/general');
        self::assertSelectorExists('#options a[href="/m/circle/panel/members"]');
    }
}
