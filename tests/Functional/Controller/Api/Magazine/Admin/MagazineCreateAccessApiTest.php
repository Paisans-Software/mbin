<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api\Magazine\Admin;

use App\Tests\WebTestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineCreateAccessApiTest extends WebTestCase
{
    private function createWithAccess(bool $admin, bool $moderator, string $access): array
    {
        $user = $this->getUserByUsername($admin ? 'admin' : 'globalmod', isAdmin: $admin, isModerator: $moderator);
        $this->client->loginUser($user);
        self::createOAuth2AuthCodeClient();

        $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: 'read write moderate:magazine_admin:create');
        $token = $codes['token_type'].' '.$codes['access_token'];

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: ['name' => 'accessmag', 'title' => 'Access magazine', 'access' => $access],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        return [];
    }

    public function testAdminCreatesHiddenMagazineViaApi(): void
    {
        $this->createWithAccess(true, false, 'hidden');

        self::assertResponseStatusCodeSame(201);
        $json = self::getJsonResponse($this->client);
        self::assertSame('hidden', $json['access']);
    }

    public function testNonAdminRestrictedAccessIsRefused(): void
    {
        $this->createWithAccess(false, true, 'private');

        self::assertResponseStatusCodeSame(400);
    }

    public function testUnknownAccessValueIs400(): void
    {
        $this->createWithAccess(true, false, 'secret');

        self::assertResponseStatusCodeSame(400);
    }
}
