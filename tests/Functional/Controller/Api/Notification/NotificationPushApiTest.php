<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api\Notification;

use App\Entity\UserPushSubscription;
use App\Service\Notification\UserPushSubscriptionManager;
use App\Tests\WebTestCase;

class NotificationPushApiTest extends WebTestCase
{
    private const array SUBSCRIPTION = [
        'endpoint' => 'https://push.example.com/endpoint',
        'serverKey' => 'c2VydmVyS2V5',
        'contentPublicKey' => 'Y29udGVudFB1YmxpY0tleQ',
    ];

    public function testApiRegisterDoesNotSendATestNotification(): void
    {
        self::createOAuth2AuthCodeClient();
        $user = $this->getUserByUsername('JohnDoe');
        $this->client->loginUser($user);
        $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: 'read user:notification:read');
        $token = $codes['token_type'].' '.$codes['access_token'];

        $this->expectNoPushSent();
        $this->client->jsonRequest('POST', '/api/notification/push', self::SUBSCRIPTION, server: ['HTTP_AUTHORIZATION' => $token]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->entityManager->getRepository(UserPushSubscription::class)->findBy(['user' => $user]));
    }

    public function testAjaxRegisterDoesNotSendATestNotification(): void
    {
        $user = $this->getUserByUsername('JohnDoe');
        $this->client->loginUser($user);

        $this->expectNoPushSent();
        $this->client->jsonRequest('POST', '/ajax/register_push', [...self::SUBSCRIPTION, 'deviceKey' => '0b1a6c1e-4f6d-4c3e-9a3b-6f2d1e0c9a7b']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->entityManager->getRepository(UserPushSubscription::class)->findBy(['user' => $user]));
    }

    private function expectNoPushSent(): void
    {
        $manager = $this->createMock(UserPushSubscriptionManager::class);
        $manager->expects($this->never())->method('sendTextToUser');
        self::getContainer()->set(UserPushSubscriptionManager::class, $manager);
    }
}
