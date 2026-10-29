<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api\Agent;

use App\Tests\WebTestCase;

class AgentMagazineCreateApiTest extends WebTestCase
{
    use AgentTokenTrait;

    private const SCOPE = 'agent:magazine:create';

    public function testCreatesMagazine(): void
    {
        $this->create($this->agentToken(self::SCOPE));

        self::assertResponseStatusCodeSame(201);
        $json = self::getJsonResponse($this->client);
        self::assertSame('welcome', $json['name']);
        self::assertSame('test_bot', $json['owner']['username']);
    }

    public function testCreatesWhenCreationRestricted(): void
    {
        $this->settingsManager->set('MBIN_RESTRICT_MAGAZINE_CREATION', true);

        $this->create($this->agentToken(self::SCOPE));

        self::assertResponseStatusCodeSame(201);
    }

    public function testPostingRestrictedFlagIsKept(): void
    {
        $this->create($this->agentToken(self::SCOPE));

        self::assertResponseStatusCodeSame(201);
        self::assertTrue(self::getJsonResponse($this->client)['isPostingRestrictedToMods']);
    }

    public function testWithoutScopeIsForbidden(): void
    {
        $this->create($this->agentToken('read'));

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->magazineRepository->findOneByName('welcome'));
    }

    public function testScopeWithoutRoleIsForbidden(): void
    {
        $this->create($this->agentToken(self::SCOPE, grantRole: false));

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->magazineRepository->findOneByName('welcome'));
    }

    private function create(string $token): void
    {
        $this->client->jsonRequest(
            'POST', '/api/agent/magazines',
            parameters: [
                'name' => 'welcome',
                'title' => 'Welcome',
                'description' => 'Say hello to new members.',
                'isAdult' => false,
                'isPostingRestrictedToMods' => true,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );
    }
}
