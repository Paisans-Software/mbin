<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

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

    private function tokenFor(User $user): string
    {
        $this->client->loginUser($user);
        $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: 'read');

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
}
