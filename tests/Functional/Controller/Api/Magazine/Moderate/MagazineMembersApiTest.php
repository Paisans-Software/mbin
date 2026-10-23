<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api\Magazine\Moderate;

use App\Entity\Magazine;
use App\Enum\MagazineAccess;
use App\Tests\WebTestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineMembersApiTest extends WebTestCase
{
    private Magazine $circle;

    public function setUp(): void
    {
        parent::setUp();

        $this->circle = $this->getMagazineByName('circle', $this->getUserByUsername('owner'));
        $this->circle->access = MagazineAccess::Private;
        $this->entityManager->flush();
    }

    private function token(string $username, string $scopes = 'read write moderate:magazine:members'): string
    {
        $this->client->loginUser($this->getUserByUsername($username));
        self::createOAuth2AuthCodeClient();
        $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: $scopes);

        return $codes['token_type'].' '.$codes['access_token'];
    }

    public function testListAddRemoveWithScope(): void
    {
        $alice = $this->getUserByUsername('alice');
        $token = $this->token('owner');
        $base = "/api/moderate/magazine/{$this->circle->getId()}/members";

        $this->client->request('GET', $base, server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame([], self::getJsonResponse($this->client)['items']);

        $this->client->request('PUT', "$base/{$alice->getId()}", server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseIsSuccessful();
        $json = self::getJsonResponse($this->client);
        self::assertSame('alice', $json['user']['username']);
        self::assertSame('owner', $json['addedBy']['username']);
        self::assertArrayHasKey('createdAt', $json);

        // idempotent
        $this->client->request('PUT', "$base/{$alice->getId()}", server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $base, server: ['HTTP_AUTHORIZATION' => $token]);
        $json = self::getJsonResponse($this->client);
        self::assertCount(1, $json['items']);
        self::assertSame('alice', $json['items'][0]['user']['username']);
        self::assertArrayHasKey('pagination', $json);

        $this->client->request('DELETE', "$base/{$alice->getId()}", server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $base, server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertSame([], self::getJsonResponse($this->client)['items']);
    }

    public function testWithoutScopeIs403(): void
    {
        $alice = $this->getUserByUsername('alice');
        $token = $this->token('owner', 'read write');

        $this->client->request('GET', "/api/moderate/magazine/{$this->circle->getId()}/members", server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('PUT', "/api/moderate/magazine/{$this->circle->getId()}/members/{$alice->getId()}", server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testNonModeratorIs403(): void
    {
        $alice = $this->getUserByUsername('alice');
        $this->getUserByUsername('carol');
        $this->memberManager()->add($this->circle, $this->getUserByUsername('carol'), $this->getUserByUsername('owner'));
        $token = $this->token('carol');

        $this->client->request('PUT', "/api/moderate/magazine/{$this->circle->getId()}/members/{$alice->getId()}", server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', "/api/moderate/magazine/{$this->circle->getId()}/members", server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRemoteUserIs400(): void
    {
        $remote = $this->getUserByUsername('remote_rita');
        $remote->apId = 'https://remote.example/u/rita';
        $this->entityManager->flush();
        $token = $this->token('owner');

        $this->client->request('PUT', "/api/moderate/magazine/{$this->circle->getId()}/members/{$remote->getId()}", server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testPublicMagazineIs404OnListAddAndRemove(): void
    {
        $open = $this->getMagazineByName('open', $this->getUserByUsername('owner'));
        $alice = $this->getUserByUsername('alice');
        $token = $this->token('owner');
        $base = "/api/moderate/magazine/{$open->getId()}/members";

        $this->client->request('GET', $base, server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('PUT', "$base/{$alice->getId()}", server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', "$base/{$alice->getId()}", server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testStrangerIs404LikeAMissingMagazine(): void
    {
        $alice = $this->getUserByUsername('alice');
        $token = $this->token('stranger');
        $base = "/api/moderate/magazine/{$this->circle->getId()}/members";
        $missing = '/api/moderate/magazine/999999/members';

        foreach ([['GET', ''], ['PUT', "/{$alice->getId()}"], ['DELETE', "/{$alice->getId()}"]] as [$method, $suffix]) {
            $this->client->request($method, $missing.$suffix, server: ['HTTP_AUTHORIZATION' => $token]);
            self::assertResponseStatusCodeSame(404, "missing $method");
            $missingProblem = $this->productionProblem();

            $this->client->request($method, $base.$suffix, server: ['HTTP_AUTHORIZATION' => $token]);
            self::assertResponseStatusCodeSame(404, "restricted $method");
            self::assertSame($missingProblem, $this->productionProblem(), $method);
        }
    }

    /**
     * The problem document without what only debug mode adds (the exception message and
     * trace); the exception class is kept so the two causes are compared too.
     *
     * @return array<string, mixed>
     */
    private function productionProblem(): array
    {
        $problem = self::getJsonResponse($this->client);
        unset($problem['detail'], $problem['trace']);

        return $problem;
    }

    private function memberManager(): \App\Service\MagazineMemberManager
    {
        /** @var \App\Repository\MagazineMemberRepository $memberRepository */
        $memberRepository = $this->entityManager->getRepository(\App\Entity\MagazineMember::class);

        return new \App\Service\MagazineMemberManager(
            $memberRepository,
            $this->magazineManager,
            $this->entityManager,
        );
    }
}
