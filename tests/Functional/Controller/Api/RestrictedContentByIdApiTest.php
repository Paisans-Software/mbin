<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\NotificationSettings;
use App\Entity\User;
use App\Tests\Functional\Controller\RestrictedContentFixtureTrait;
use App\Tests\WebTestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class RestrictedContentByIdApiTest extends WebTestCase
{
    use RestrictedContentFixtureTrait;

    private string $aliceToken;
    private string $bobToken;

    public function setUp(): void
    {
        parent::setUp();
        $this->createRestrictedFixture();

        self::createOAuth2AuthCodeClient();
        $this->aliceToken = $this->tokenFor($this->alice);
        $this->bobToken = $this->tokenFor($this->bob);
    }

    private function tokenFor(User $user, string $scopes = 'read'): string
    {
        $this->client->loginUser($user);
        $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: $scopes);

        return $codes['token_type'].' '.$codes['access_token'];
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        return [
            '/api/entry/'.$this->entry->getId(),
            '/api/post/'.$this->post->getId(),
            '/api/comments/'.$this->entryComment->getId(),
            '/api/post-comments/'.$this->postComment->getId(),
            '/api/entry/'.$this->entry->getId().'/activity',
            '/api/post/'.$this->post->getId().'/activity',
            '/api/comments/'.$this->entryComment->getId().'/activity',
            '/api/post-comments/'.$this->postComment->getId().'/activity',
        ];
    }

    public function testNonMemberGets404(): void
    {
        foreach ($this->paths() as $path) {
            $this->client->jsonRequest('GET', $path, server: ['HTTP_AUTHORIZATION' => $this->bobToken]);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testAnonymousGets404(): void
    {
        foreach ($this->paths() as $path) {
            $this->client->jsonRequest('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testMemberGets200(): void
    {
        foreach ($this->paths() as $path) {
            $this->client->jsonRequest('GET', $path, server: ['HTTP_AUTHORIZATION' => $this->aliceToken]);
            self::assertResponseIsSuccessful($path);
        }
    }

    public function testNotificationSettingOnRestrictedTargetsIs404ForNonMember(): void
    {
        $token = $this->tokenFor($this->bob, 'read user:notification:edit');
        $aliceToken = $this->tokenFor($this->alice, 'read user:notification:edit');
        $paths = [
            '/api/notification/update/entry/'.$this->entry->getId().'/Loud',
            '/api/notification/update/post/'.$this->post->getId().'/Loud',
            '/api/notification/update/magazine/'.$this->circle->getId().'/Loud',
        ];

        $this->client->request('PUT', '/api/notification/update/entry/999999/Loud', server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseStatusCodeSame(404);
        $missing = $this->productionProblem();

        foreach ($paths as $path) {
            $this->client->request('PUT', $path, server: ['HTTP_AUTHORIZATION' => $token]);
            self::assertResponseStatusCodeSame(404, $path);
            self::assertSame($missing, $this->productionProblem(), $path);
        }
        self::assertSame(0, $this->entityManager->getRepository(NotificationSettings::class)->count(['user' => $this->bob->getId()]));
        self::assertNotEmpty($missing);

        foreach ($paths as $path) {
            $this->client->request('PUT', $path, server: ['HTTP_AUTHORIZATION' => $aliceToken]);
            self::assertResponseIsSuccessful($path);
        }
    }

    /**
     * The problem document without what only debug mode adds (the exception message and
     * trace); the exception class stays, so both answers have the same cause.
     *
     * @return array<string, mixed>
     */
    private function productionProblem(): array
    {
        $problem = self::getJsonResponse($this->client);
        unset($problem['detail'], $problem['trace']);

        return $problem;
    }
}
