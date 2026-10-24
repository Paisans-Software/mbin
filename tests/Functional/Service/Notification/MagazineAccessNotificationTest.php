<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Notification;

use App\Entity\Magazine;
use App\Entity\MagazineAccessGrantedNotification;
use App\Entity\MagazineAccessRequest;
use App\Entity\MagazineAccessRequestedNotification;
use App\Entity\MagazineSubscription;
use App\Entity\Moderator;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Service\MagazineAccessRequestManager;
use App\Service\MagazineMemberManager;
use App\Service\NotificationManager;
use App\Tests\WebTestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineAccessNotificationTest extends WebTestCase
{
    private Magazine $circle;
    private User $owner;
    private User $second;
    private User $bob;

    public function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->getUserByUsername('owner');
        $this->second = $this->getUserByUsername('second');
        $this->bob = $this->getUserByUsername('bob');
        $this->circle = $this->getMagazineByName('circle', $this->owner);
        $this->circle->access = MagazineAccess::Private;
        $this->entityManager->persist(new Moderator($this->circle, $this->second, $this->owner, false, true));
        $this->entityManager->flush();
    }

    private function manager(): MagazineAccessRequestManager
    {
        return static::getContainer()->get(MagazineAccessRequestManager::class);
    }

    public function testModeratorsNotifiedOnRequest(): void
    {
        $this->manager()->request($this->circle, $this->bob);
        // A repeated request creates no second notification.
        $this->manager()->request($this->circle, $this->bob);

        self::assertSame(1, $this->notificationRepository->countUnreadNotifications($this->owner));
        self::assertSame(1, $this->notificationRepository->countUnreadNotifications($this->second));
        self::assertSame(0, $this->notificationRepository->countUnreadNotifications($this->bob));
        $notification = $this->notificationRepository->findOneBy(['user' => $this->owner]);
        self::assertInstanceOf(MagazineAccessRequestedNotification::class, $notification);
        self::assertSame('magazine_access_requested', $notification->getType());
    }

    public function testRequesterNotifiedOnApprove(): void
    {
        $this->manager()->request($this->circle, $this->bob);
        $request = $this->entityManager->getRepository(MagazineAccessRequest::class)->findOneBy([]);

        $this->manager()->approve($request, $this->owner);

        self::assertSame(1, $this->notificationRepository->countUnreadNotifications($this->bob));
        $notification = $this->notificationRepository->findOneBy(['user' => $this->bob]);
        self::assertInstanceOf(MagazineAccessGrantedNotification::class, $notification);
        self::assertSame('magazine_access_granted', $notification->getType());
        self::assertSame('circle', $notification->magazine->name);
    }

    public function testDirectlyAddedMemberIsNotifiedOnceAndNotSubscribed(): void
    {
        $vault = $this->getMagazineByName('vault', $this->owner);
        $vault->access = MagazineAccess::Hidden;
        $this->entityManager->flush();
        $members = static::getContainer()->get(MagazineMemberManager::class);

        foreach ([$this->circle, $vault] as $i => $magazine) {
            $members->add($magazine, $this->bob, $this->owner);
            // Adding an existing member again notifies nobody.
            $members->add($magazine, $this->bob, $this->owner);

            self::assertSame($i + 1, $this->notificationRepository->countUnreadNotifications($this->bob), $magazine->name);
            $notification = $this->entityManager->getRepository(MagazineAccessGrantedNotification::class)->findOneBy(['user' => $this->bob, 'magazine' => $magazine]);
            self::assertInstanceOf(MagazineAccessGrantedNotification::class, $notification);
            self::assertNull($this->entityManager->getRepository(MagazineSubscription::class)->findOneBy(['magazine' => $magazine, 'user' => $this->bob]), $magazine->name);
        }
    }

    public function testAddingSomeoneWhoCanAlreadyViewNotifiesNobody(): void
    {
        static::getContainer()->get(MagazineMemberManager::class)->add($this->circle, $this->second, $this->owner);

        self::assertSame(0, $this->notificationRepository->countUnreadNotifications($this->second));
    }

    public function testNotificationsApiSerializesBothTypes(): void
    {
        self::createOAuth2AuthCodeClient();
        $this->manager()->request($this->circle, $this->bob);
        static::getContainer()->get(NotificationManager::class)->sendMagazineAccessGrantedNotification($this->circle, $this->bob);

        $this->client->loginUser($this->owner);
        $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: 'read user:notification:read');
        $token = $codes['token_type'].' '.$codes['access_token'];
        $this->client->request('GET', '/api/notifications/all', server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseIsSuccessful();
        $items = self::getJsonResponse($this->client)['items'];
        self::assertSame('magazine_access_requested', $items[0]['type']);
        self::assertSame('circle', $items[0]['subject']['magazine']['name']);
        self::assertSame('bob', $items[0]['subject']['user']['username']);

        $this->client->loginUser($this->bob);
        $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: 'read user:notification:read');
        $token = $codes['token_type'].' '.$codes['access_token'];
        $this->client->request('GET', '/api/notifications/all', server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseIsSuccessful();
        $items = self::getJsonResponse($this->client)['items'];
        self::assertSame('magazine_access_granted', $items[0]['type']);
        self::assertSame('circle', $items[0]['subject']['name']);
    }

    public function testDemotedModeratorNoLongerSeesRequestNotifications(): void
    {
        $this->manager()->request($this->circle, $this->bob);
        $page = fn () => iterator_to_array($this->notificationRepository->findByUser($this->second, 1));

        self::assertCount(1, $page());

        $moderator = $this->entityManager->getRepository(Moderator::class)->findOneBy(['user' => $this->second]);
        $this->entityManager->remove($moderator);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertCount(0, iterator_to_array($this->notificationRepository->findByUser($this->entityManager->find(User::class, $this->second->getId()), 1)));
        // The owner still moderates the magazine and still sees it.
        self::assertCount(1, iterator_to_array($this->notificationRepository->findByUser($this->entityManager->find(User::class, $this->owner->getId()), 1)));
    }
}
