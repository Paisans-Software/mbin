<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Magazine\Panel;

use App\DTO\MagazineBanDto;
use App\Entity\Magazine;
use App\Entity\MagazineAccessRequest;
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
        $this->request($this->circle, $this->bob);
        static::getContainer()->get(MagazineManager::class)->ban(
            $this->circle,
            $this->bob,
            $this->owner,
            MagazineBanDto::create('test ban', new \DateTimeImmutable('+1 day'))
        );
        $id = $this->pending()[0]->getId();

        $this->client->loginUser($this->getUserByUsername('owner'));
        $this->decide('approve', $id);

        self::assertResponseRedirects('/m/circle/panel/requests');
        self::assertCount(0, $this->pending());
        $circle = $this->entityManager->getRepository(Magazine::class)->findOneBy(['name' => 'circle']);
        self::assertFalse($circle->userCanView($this->getUserByUsername('bob')));
        self::assertNull($this->entityManager->getRepository(\App\Entity\MagazineAccessGrantedNotification::class)->findOneBy([]));
    }

    public function testOtherMagazineModeratorCannotSeeRequests(): void
    {
        $this->request($this->circle, $this->bob);
        $id = $this->pending()[0]->getId();
        $other = $this->getUserByUsername('other');
        $this->getMagazineByName('elsewhere', $other);

        $this->client->loginUser($other);
        $this->client->request('GET', '/m/circle/panel/requests');
        self::assertResponseStatusCodeSame(404);

        // A non-viewer gets the 404 of a nonexistent page. An id belonging to another magazine is a 404 even for that magazine's own moderator.
        $this->client->request('POST', '/m/elsewhere/panel/requests/'.$id.'/approve', ['token' => 'x']);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->pending());
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
}
