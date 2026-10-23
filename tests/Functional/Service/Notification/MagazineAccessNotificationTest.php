<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Notification;

use App\Entity\Magazine;
use App\Entity\MagazineAccessGrantedNotification;
use App\Entity\MagazineAccessRequest;
use App\Entity\MagazineAccessRequestedNotification;
use App\Entity\Moderator;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Service\MagazineAccessRequestManager;
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
}
