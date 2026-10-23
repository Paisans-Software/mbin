<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api\Magazine;

use App\Entity\Magazine;
use App\Enum\MagazineAccess;
use App\Tests\WebTestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineGateApiTest extends WebTestCase
{
    private Magazine $circle;
    private Magazine $vault;

    public function setUp(): void
    {
        parent::setUp();

        $admin = $this->getUserByUsername('admin', isAdmin: true);
        $this->circle = $this->getMagazineByName('circle', $admin);
        $this->circle->access = MagazineAccess::Private;
        $this->vault = $this->getMagazineByName('vault', $admin);
        $this->vault->access = MagazineAccess::Hidden;
        $this->entityManager->flush();
    }

    private function token(): string
    {
        $this->client->loginUser($this->getUserByUsername('bob'));
        self::createOAuth2AuthCodeClient();
        $codes = self::getAuthorizationCodeTokenResponse($this->client);

        return $codes['token_type'].' '.$codes['access_token'];
    }

    public function testApiSingleMagazineForNonMemberHasNoModerators(): void
    {
        $token = $this->token();

        $this->client->request('GET', "/api/magazine/{$this->circle->getId()}", server: ['HTTP_AUTHORIZATION' => $token]);

        self::assertResponseIsSuccessful();
        $json = self::getJsonResponse($this->client);
        self::assertSame([], $json['moderators']);
        self::assertNull($json['owner']);
        self::assertSame('circle', $json['name']);
    }

    public function testApiSingleMagazineByNameForNonMemberHasNoModerators(): void
    {
        $token = $this->token();

        $this->client->request('GET', '/api/magazine/name/circle', server: ['HTTP_AUTHORIZATION' => $token]);

        self::assertResponseIsSuccessful();
        $json = self::getJsonResponse($this->client);
        self::assertSame([], $json['moderators']);
        self::assertNull($json['owner']);
    }

    public function testApiSingleHiddenMagazineIs404(): void
    {
        $token = $this->token();

        $this->client->request('GET', "/api/magazine/{$this->vault->getId()}", server: ['HTTP_AUTHORIZATION' => $token]);

        self::assertResponseStatusCodeSame(404);
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

    public function testApiHiddenMagazineIndistinguishableFromMissing(): void
    {
        $token = $this->token();
        $pairs = [
            ['/api/magazine/name/vault', '/api/magazine/name/nosuchmag'],
            ["/api/magazine/{$this->vault->getId()}", '/api/magazine/999999'],
        ];

        foreach ($pairs as [$hiddenPath, $missingPath]) {
            $this->client->request('GET', $missingPath, server: ['HTTP_AUTHORIZATION' => $token]);
            self::assertResponseStatusCodeSame(404, $missingPath);
            $missing = $this->productionProblem();

            $this->client->request('GET', $hiddenPath, server: ['HTTP_AUTHORIZATION' => $token]);
            self::assertResponseStatusCodeSame(404, $hiddenPath);
            self::assertSame($missing, $this->productionProblem(), $hiddenPath);
        }
    }

    public function testApiMagazineEntriesForNonMemberIs404(): void
    {
        $token = $this->token();

        $this->client->request('GET', "/api/magazine/{$this->circle->getId()}/entries", server: ['HTTP_AUTHORIZATION' => $token]);

        self::assertResponseStatusCodeSame(404);
    }
}
