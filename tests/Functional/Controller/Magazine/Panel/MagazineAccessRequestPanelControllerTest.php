<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Magazine\Panel;

use App\DTO\MagazineBanDto;
use App\Entity\Magazine;
use App\Entity\MagazineAccessGrantedNotification;
use App\Entity\MagazineAccessRequest;
use App\Entity\MagazineMember;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Service\MagazineAccessRequestManager;
use App\Service\MagazineManager;
use App\Tests\WebTestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineAccessRequestPanelControllerTest extends WebTestCase
{
    private Magazine $circle;
    private Magazine $vault;
    private User $owner;
    private User $bob;

    public function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->getUserByUsername('owner');
        $this->circle = $this->getMagazineByName('circle', $this->owner);
        $this->circle->access = MagazineAccess::Private;
        $this->vault = $this->getMagazineByName('vault', $this->owner);
        $this->vault->access = MagazineAccess::Hidden;
        $this->getMagazineByName('garden', $this->owner);
        $this->entityManager->flush();

        $this->bob = $this->getUserByUsername('bob');
        $this->createEntry('Inside the circle', $this->circle, $this->owner);
    }

    private function request(Magazine $magazine, User $user): void
    {
        static::getContainer()->get(MagazineAccessRequestManager::class)->request($magazine, $user);
    }

    /** @return MagazineAccessRequest[] */
    private function pending(): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(MagazineAccessRequest::class)->findAll();
    }

    private function decide(string $action, int $id, string $name = 'circle'): void
    {
        $crawler = $this->client->request('GET', '/m/circle/panel/requests');
        $token = $crawler->filter('form[action$="/requests/'.$id.'/'.$action.'"] input[name=token]')->attr('value');
        $this->client->request('POST', '/m/'.$name.'/panel/requests/'.$id.'/'.$action, ['token' => $token]);
    }

    public function testModeratorSeesPendingRequests(): void
    {
        $this->request($this->circle, $this->bob);

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/m/circle/panel/requests');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#main .options__main a.active', 'Requests');
        self::assertSelectorTextContains('#main .access-requests-table', 'bob');
        self::assertSelectorExists('#main form[action$="/approve"]');
        self::assertSelectorExists('#main form[action$="/deny"]');
    }

    public function testApproveAddsMemberAndRemovesRequest(): void
    {
        $this->request($this->circle, $this->bob);
        $id = $this->pending()[0]->getId();

        $this->client->loginUser($this->bob);
        $this->client->request('GET', '/m/circle');
        self::assertSelectorExists('[data-magazine-stub]');

        $this->client->loginUser($this->owner);
        $this->decide('approve', $id);
        self::assertResponseRedirects('/m/circle/panel/requests');

        self::assertCount(0, $this->pending());
        $this->client->loginUser($this->bob);
        $this->client->request('GET', '/m/circle');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-magazine-stub]');
        self::assertSelectorTextContains('#content', 'Inside the circle');
    }

    public function testDenyRemovesRequestSilently(): void
    {
        $this->request($this->circle, $this->bob);
        $id = $this->pending()[0]->getId();

        $this->client->loginUser($this->owner);
        $this->decide('deny', $id);

        self::assertCount(0, $this->pending());
        $this->entityManager->clear();
        self::assertSame(0, $this->notificationRepository->countUnreadNotifications($this->getUserByUsername('bob')));
        $this->client->loginUser($this->getUserByUsername('bob'));
        $this->client->request('GET', '/m/circle');
        self::assertSelectorExists('[data-magazine-stub]');
    }

    public function testApproveForBannedUserAddsNobody(): void
    {
        static::getContainer()->get(MagazineManager::class)->ban(
            $this->circle,
            $this->bob,
            $this->owner,
            MagazineBanDto::create('test ban', new \DateTimeImmutable('+1 day'))
        );
        // A ban drops a pending request, so a stale row is planted directly.
        $this->entityManager->persist(new MagazineAccessRequest($this->circle, $this->bob));
        $this->entityManager->flush();
        $id = $this->pending()[0]->getId();

        $this->client->loginUser($this->getUserByUsername('owner'));
        $this->decide('approve', $id);

        self::assertResponseRedirects('/m/circle/panel/requests');
        self::assertCount(0, $this->pending());
        $circle = $this->entityManager->getRepository(Magazine::class)->findOneBy(['name' => 'circle']);
        self::assertFalse($circle->userCanView($this->getUserByUsername('bob')));
        self::assertNull($this->entityManager->getRepository(MagazineAccessGrantedNotification::class)->findOneBy([]));
    }

    public function testOtherMagazineModeratorCannotSeeRequests(): void
    {
        $this->request($this->circle, $this->bob);
        $id = $this->pending()[0]->getId();
        $other = $this->getUserByUsername('other');
        $elsewhere = $this->getMagazineByName('elsewhere', $other);
        $elsewhere->access = MagazineAccess::Private;
        $this->entityManager->flush();

        $this->client->loginUser($other);
        $this->client->request('GET', '/m/circle/panel/requests');
        self::assertResponseStatusCodeSame(404);

        // A non-viewer gets the 404 of a nonexistent page. An id belonging to another magazine is a 404 even for that
        // (private) magazine's own moderator, with a valid token, so it is resolve()'s id comparison that refuses it.
        // The other moderator's own Requests tab holds a request, so its form carries a valid token.
        $this->request($elsewhere, $this->getUserByUsername('carol'));
        $this->client->loginUser($other);
        $crawler = $this->client->request('GET', '/m/elsewhere/panel/requests');
        $token = $crawler->filter('form[action$="/approve"] input[name=token]')->attr('value');
        $this->client->request('POST', '/m/elsewhere/panel/requests/'.$id.'/approve', ['token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($this->entityManager->getRepository(MagazineAccessRequest::class)->find($id));
    }

    public function testTabAbsentOnHiddenAndPublic(): void
    {
        $this->client->loginUser($this->owner);

        $this->client->request('GET', '/m/circle/panel/general');
        self::assertSelectorExists('#options a[href$="/m/circle/panel/requests"]');

        foreach (['vault', 'garden'] as $name) {
            $this->client->request('GET', '/m/'.$name.'/panel/general');
            self::assertSelectorNotExists('#options a[href$="/panel/requests"]');
            $this->client->request('GET', '/m/'.$name.'/panel/requests');
            self::assertResponseStatusCodeSame(404);
        }
    }

    private function plant(User $user): int
    {
        $this->entityManager->persist(new MagazineAccessRequest($this->circle, $user));
        $this->entityManager->flush();

        return $this->pending()[0]->getId();
    }

    private function approveRefused(User $user): void
    {
        $id = $this->plant($user);
        $this->client->loginUser($this->owner);
        $this->decide('approve', $id);
    }

    public function testApproveRefusedForRemoteUser(): void
    {
        $remote = $this->getUserByUsername('remoteuser');
        $remote->apId = 'https://remote.example/u/remoteuser';
        $this->entityManager->flush();

        $this->approveRefused($remote);

        self::assertResponseRedirects('/m/circle/panel/requests');
        self::assertCount(0, $this->pending());
        self::assertSame(0, $this->entityManager->getRepository(MagazineMember::class)->count([]));
    }

    public function testApproveRefusedForInstanceBannedUser(): void
    {
        $this->bob->isBanned = true;
        $this->entityManager->flush();

        $this->approveRefused($this->bob);

        self::assertResponseRedirects('/m/circle/panel/requests');
        self::assertCount(0, $this->pending());
        self::assertSame(0, $this->entityManager->getRepository(MagazineMember::class)->count([]));
    }

    public function testApproveRefusedForDeletedUser(): void
    {
        $this->bob->isDeleted = true;
        $this->entityManager->flush();

        $this->approveRefused($this->bob);

        self::assertResponseRedirects('/m/circle/panel/requests');
        self::assertCount(0, $this->pending());
        self::assertSame(0, $this->entityManager->getRepository(MagazineMember::class)->count([]));
    }

    public function testApproveOnNoLongerPrivateMagazineAddsNobody(): void
    {
        $id = $this->plant($this->bob);
        $request = $this->entityManager->getRepository(MagazineAccessRequest::class)->find($id);
        $this->circle = $request->magazine;
        $this->circle->access = MagazineAccess::Public;
        $this->entityManager->flush();

        static::getContainer()->get(MagazineAccessRequestManager::class)->approve($request, $this->owner);

        self::assertCount(0, $this->pending());
        self::assertSame(0, $this->entityManager->getRepository(MagazineMember::class)->count([]));
        self::assertNull($this->entityManager->getRepository(MagazineAccessGrantedNotification::class)->findOneBy([]));
    }

    public function testApproveForExistingMemberSendsNoGrantedNotification(): void
    {
        $id = $this->plant($this->bob);
        $request = $this->entityManager->getRepository(MagazineAccessRequest::class)->find($id);
        $this->entityManager->persist(new MagazineMember($request->magazine, $request->user, $this->entityManager->find(User::class, $this->owner->getId())));
        $this->entityManager->flush();

        static::getContainer()->get(MagazineAccessRequestManager::class)->approve($request, $this->owner);

        self::assertCount(0, $this->pending());
        self::assertNull($this->entityManager->getRepository(MagazineAccessGrantedNotification::class)->findOneBy([]));
    }

    public function testDecisionsWithoutTokenAreRejected(): void
    {
        $id = $this->plant($this->bob);
        $this->client->loginUser($this->owner);

        foreach (['approve', 'deny'] as $action) {
            $this->client->request('POST', '/m/circle/panel/requests/'.$id.'/'.$action);
            self::assertResponseStatusCodeSame(400);
            $this->client->request('POST', '/m/circle/panel/requests/'.$id.'/'.$action, ['token' => 'bogus']);
            self::assertResponseStatusCodeSame(400);
        }
        self::assertCount(1, $this->pending());
    }
}
